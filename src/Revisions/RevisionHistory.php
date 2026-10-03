<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Usages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * Keeps the steps that led to a saved revised image in the asset library, so
 * its story keeps its pictures: the original and every earlier round of its
 * line, at full size, in a "revisions" folder beside it. Without this they
 * would go with the rest of temporary storage within a day.
 *
 * A step that is already an asset (the original, or a round that was saved
 * itself) is referred to, not copied. A copy is written straight to the
 * container and saved as an asset, not uploaded, so no Glide presets are
 * made for images nobody chose to use, and it is tagged (ThreadAssets
 * REVISION_KEY) rather than stamped, so History, Trash and Spend leave it
 * alone.
 *
 * Every step lists the saved images whose history includes it, by round id,
 * which survives moves and renames. A step is deleted only when that list is
 * empty and it is not used on the site, so deleting one image never takes a
 * step another image's story still needs, and no site-wide scan ever
 * decides what to delete. A saved image deleted outside Statamic leaves its
 * steps in place, which is the safe way to fail.
 */
class RevisionHistory
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(
        protected BatchStore $store,
        protected ThreadAssets $assets,
        protected Usages $usages,
        protected array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['revise']['history']['save'] ?? true);
    }

    /**
     * Keep the steps that led to a round that has just been saved.
     *
     * @param  array<string, mixed>  $batch  The saved round's batch, as BatchStore::find returns it.
     * @param  AssetContract  $saved  The asset it was saved as.
     * @return array<int, int> The steps whose image was no longer there: 0 for the original, otherwise the round's number.
     */
    public function keep(array $batch, AssetContract $saved): array
    {
        $thread = BatchStore::threadOf($batch);

        if (! $thread || ! $this->enabled()) {
            return [];
        }

        // Two rounds of one thread saved at the same moment would otherwise
        // both write a copy of a step they share.
        return Cache::lock("darkroom-history-{$thread}", 120)->block(60, fn () => $this->keepLocked($thread, $batch, $saved));
    }

    /**
     * Fill in the history of images saved before it was kept, while their
     * rounds are still in temporary storage. Once, on the first clean-up
     * after this was added; keep() is safe to repeat, so a second run would
     * only find nothing to do.
     *
     * @return int How many images were looked at.
     */
    public function backfill(): int
    {
        if (! $this->enabled() || Cache::get('darkroom.history.backfilled')) {
            return 0;
        }

        // Marked done only once it has finished, so a run that is cut off
        // is tried again; the lock stops two page loads doing it at once.
        return Cache::lock('darkroom-history-backfill', 600)->get(function () {
            $count = 0;

            foreach (AssetContainer::all() as $container) {
                foreach ($container->queryAssets()->whereNotNull(SavedImages::KEY)->get() as $asset) {
                    $round = ThreadAssets::savedRound((array) $asset->get(SavedImages::KEY));
                    $batch = $round ? $this->store->find($round) : null;

                    if (! $batch || ! BatchStore::threadOf($batch) || ($batch['items'][0]['history_kept'] ?? false)) {
                        continue;
                    }

                    try {
                        $this->keep($batch, $asset);
                        $this->store->updateItem($round, 1, ['history_kept' => true]);
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            Cache::forever('darkroom.history.backfilled', now()->getTimestamp());

            return $count;
        }) ?: 0;
    }

    /**
     * A saved image's history is no longer wanted: it was deleted, or its
     * history was. Each step stops listing it, and a step nothing lists any
     * more is deleted, unless it is used on the site.
     *
     * @param  mixed  $user  When given, a step this user may not delete is kept.
     * @return array{deleted: array<int, string>, kept: array<int, array{path: string, reason: string}>}
     */
    public function release(string $thread, string $savedRound, $user = null): array
    {
        if (! BatchStore::validId($thread) || ! BatchStore::validId($savedRound)) {
            return ['deleted' => [], 'kept' => []];
        }

        // The same lock as keep(), so a step a save is claiming at this
        // moment cannot be deleted from under it.
        return Cache::lock("darkroom-history-{$thread}", 120)->block(60, fn () => $this->releaseLocked($thread, $savedRound, $user));
    }

    /**
     * Before a step that other saved images' stories show is deleted (an
     * earlier round that was saved as an image of its own, or an original
     * that was already an asset), keep a copy of it for them, so deleting
     * one image never takes a picture from another's story. Also before an
     * image stops being findable as its round without being deleted (its
     * own history deleted, or Darkroom no longer listing it), with
     * $asOriginal false: as an original it is still found by its tag.
     */
    public function preserve(AssetContract $asset, bool $asOriginal = true): void
    {
        if (! $this->enabled()) {
            return;
        }

        $stamp = (array) $asset->get(SavedImages::KEY);
        $thread = $stamp['thread']['id'] ?? null;

        if (is_string($thread) && BatchStore::validId($thread) && ($round = ThreadAssets::savedRound($stamp))) {
            Cache::lock("darkroom-history-{$thread}", 120)->block(60, fn () => $this->preserveStep($asset, $thread, $round));
        }

        // An original stays findable by its own tag unless it is deleted.
        foreach ($asOriginal ? (array) $asset->get(ThreadAssets::ORIGIN_KEY) : [] as $origin => $saved) {
            if (is_string($origin) && BatchStore::validId($origin) && $saved) {
                Cache::lock("darkroom-history-{$origin}", 120)->block(60, fn () => $this->preserveStep($asset, $origin, 'origin'));
            }
        }
    }

    /**
     * @param  mixed  $user
     * @return array{deleted: array<int, string>, kept: array<int, array{path: string, reason: string}>}
     */
    protected function releaseLocked(string $thread, string $savedRound, $user): array
    {
        $result = ['deleted' => [], 'kept' => []];
        $unwanted = [];

        foreach ($this->assets->revisions($thread) as $asset) {
            $tag = (array) $asset->get(ThreadAssets::REVISION_KEY);
            $saved = array_values(array_filter((array) ($tag['saved'] ?? []), 'is_string'));

            if (! in_array($savedRound, $saved, true)) {
                continue;
            }

            $tag['saved'] = array_values(array_diff($saved, [$savedRound]));
            $asset->set(ThreadAssets::REVISION_KEY, $tag)->save();

            if ($tag['saved'] !== []) {
                $result['kept'][] = ['path' => $asset->path(), 'reason' => 'shared'];
            } elseif ($user && ! Gate::forUser($user)->allows('delete', $asset)) {
                $result['kept'][] = ['path' => $asset->path(), 'reason' => 'permission'];
            } else {
                $unwanted[] = $asset;
            }
        }

        // Reading the site's content is slow, so it is only done when there
        // is something to delete, and once for all of it.
        $used = $unwanted === [] ? [] : $this->usages->find($unwanted);

        foreach ($unwanted as $asset) {
            if ($used[$asset->id()] ?? []) {
                $result['kept'][] = ['path' => $asset->path(), 'reason' => 'used'];

                continue;
            }

            $result['deleted'][] = $asset->path();
            $asset->delete();
        }

        foreach ($this->assets->origins($thread) as $asset) {
            $origins = (array) $asset->get(ThreadAssets::ORIGIN_KEY);
            $left = array_values(array_diff((array) ($origins[$thread] ?? []), [$savedRound]));

            if ($left === []) {
                unset($origins[$thread]);
            } else {
                $origins[$thread] = $left;
            }

            $origins === [] ? $asset->remove(ThreadAssets::ORIGIN_KEY) : $asset->set(ThreadAssets::ORIGIN_KEY, $origins);
            $asset->save();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return array<int, int>
     */
    protected function keepLocked(string $thread, array $batch, AssetContract $saved): array
    {
        $savedRound = $batch['id'];
        $line = $this->line($batch);
        $found = $this->assets->find($thread);
        $missing = [];

        $container = $saved->container();
        $folder = $this->folder($saved);
        $base = pathinfo($saved->basename(), PATHINFO_FILENAME);

        // What a copy needs to be revised again later: the prompt it was
        // made for and the shape it was made in.
        $about = array_filter([
            'prompt' => $batch['prompt'] ?? null,
            'aspect_ratio' => $batch['aspect_ratio'] ?? null,
        ], fn ($value) => $value !== null);

        if ($origin = $found['origin'] ?? $this->originAsset($batch)) {
            $this->refer($origin, $thread, $savedRound);
        } elseif ($bytes = $this->originBytes($batch, $line)) {
            $this->write($container, $folder, "{$base}-original", $bytes, [
                'thread' => $thread,
                'round' => 'origin',
                'step' => 0,
                'saved' => [$savedRound],
            ] + $about);
        } else {
            $missing[] = 0;
        }

        foreach ($line as $index => $round) {
            $step = $index + 1;

            if ($existing = $found['rounds'][$round['id']] ?? null) {
                // A copy kept for another saved image is shared. A round that
                // was saved as an image of its own is referred to as it is.
                if ($existing->get(ThreadAssets::REVISION_KEY)) {
                    $this->refer($existing, $thread, $savedRound);
                }

                continue;
            }

            // The round's own image while its batch has it, otherwise the copy
            // the next round in the line was made from.
            $next = $line[$index + 1]['id'] ?? $batch['id'];
            $bytes = $this->store->original($round['id'], 1) ?? $this->store->source($next);

            if (! $bytes) {
                $missing[] = $step;

                continue;
            }

            $this->write($container, $folder, "{$base}-round-{$step}", $bytes, array_filter([
                'thread' => $thread,
                'round' => $round['id'],
                'step' => $step,
                'saved' => [$savedRound],
                'model' => $round['model'] ?? null,
                'quality' => $round['quality'] ?? null,
                'interaction' => $round['interaction'] ?? null,
                'at' => $round['at'] ?? null,
            ], fn ($value) => $value !== null) + $about);
        }

        return $missing;
    }

    /**
     * Keep a copy of a step that is about to be deleted, for the other saved
     * images of the thread whose line includes it.
     */
    protected function preserveStep(AssetContract $asset, string $thread, string $round): void
    {
        $dependents = [];

        foreach (AssetContainer::all() as $container) {
            foreach ($container->queryAssets()->whereNotNull(SavedImages::KEY)->get() as $image) {
                $stamp = (array) $image->get(SavedImages::KEY);
                $own = ThreadAssets::savedRound($stamp);

                if (($stamp['thread']['id'] ?? null) !== $thread || ! $own || $own === $round || $image->id() === $asset->id()) {
                    continue;
                }

                $line = array_values((array) ($stamp['thread']['rounds'] ?? []));
                $position = array_search($round, array_column($line, 'id'), true);

                if ($round === 'origin' || $position !== false) {
                    $dependents[$own] = [$image, $stamp, $round === 'origin' ? 0 : $position + 1, $line[$position] ?? []];
                }
            }
        }

        if ($dependents === []) {
            return;
        }

        // Already kept as a copy for one of them: share it with the rest.
        foreach ($this->assets->revisions($thread) as $copy) {
            if (((array) $copy->get(ThreadAssets::REVISION_KEY))['round'] === $round) {
                foreach (array_keys($dependents) as $saved) {
                    $this->refer($copy, $thread, $saved);
                }

                return;
            }
        }

        [$image, $stamp, $step, $entry] = reset($dependents);
        $base = pathinfo($image->basename(), PATHINFO_FILENAME);

        $this->write($image->container(), $this->folder($image), $step === 0 ? "{$base}-original" : "{$base}-round-{$step}", $asset->contents(), array_filter([
            'thread' => $thread,
            'round' => $round,
            'step' => $step,
            'saved' => array_keys($dependents),
            'prompt' => $stamp['prompt'] ?? null,
            'aspect_ratio' => $stamp['aspect_ratio'] ?? null,
            'model' => $entry['model'] ?? null,
            'quality' => $entry['quality'] ?? null,
            'interaction' => $entry['interaction'] ?? null,
            'at' => $entry['at'] ?? null,
        ], fn ($value) => $value !== null));
    }

    /**
     * The rounds before the saved one, oldest first, as its batch carries them.
     *
     * @param  array<string, mixed>  $batch
     * @return array<int, array<string, mixed>>
     */
    protected function line(array $batch): array
    {
        return array_values(array_filter(
            (array) ($batch['thread']['ancestors'] ?? []),
            fn ($round) => is_array($round) && is_string($round['id'] ?? null) && BatchStore::validId($round['id']),
        ));
    }

    /**
     * The original, when it already is an asset: it started as one, or the
     * image it started from has been saved since.
     *
     * @param  array<string, mixed>  $batch
     */
    protected function originAsset(array $batch): ?AssetContract
    {
        $origin = (array) ($batch['thread']['origin'] ?? []);

        if (is_string($origin['asset'] ?? null)) {
            return Asset::find($origin['asset']);
        }

        if (is_string($origin['batch'] ?? null) && BatchStore::validId($origin['batch'])) {
            $id = $this->store->item($origin['batch'], (int) ($origin['index'] ?? 1))['asset']['id'] ?? null;

            return $id ? Asset::find($id) : null;
        }

        return null;
    }

    /**
     * The image the first round started from: the copy that round was made
     * from, or the original's own batch.
     *
     * @param  array<string, mixed>  $batch
     * @param  array<int, array<string, mixed>>  $line
     */
    protected function originBytes(array $batch, array $line): ?string
    {
        if ($bytes = $this->store->source($line[0]['id'] ?? $batch['id'])) {
            return $bytes;
        }

        $origin = (array) ($batch['thread']['origin'] ?? []);

        return is_string($origin['batch'] ?? null) && BatchStore::validId($origin['batch'])
            ? $this->store->original($origin['batch'], (int) ($origin['index'] ?? 1))
            : null;
    }

    /**
     * Record that a saved image's history includes an asset that already
     * exists: a copy kept for another image, or an original asset.
     */
    protected function refer(AssetContract $asset, string $thread, string $savedRound): void
    {
        $tag = (array) $asset->get(ThreadAssets::REVISION_KEY);

        if (($tag['thread'] ?? null) === $thread) {
            $saved = array_values((array) ($tag['saved'] ?? []));

            if (! in_array($savedRound, $saved, true)) {
                $tag['saved'] = [...$saved, $savedRound];
                $asset->set(ThreadAssets::REVISION_KEY, $tag)->save();
            }

            return;
        }

        $origins = (array) $asset->get(ThreadAssets::ORIGIN_KEY);
        $listed = array_values((array) ($origins[$thread] ?? []));

        if (! in_array($savedRound, $listed, true)) {
            $origins[$thread] = [...$listed, $savedRound];
            $asset->set(ThreadAssets::ORIGIN_KEY, $origins)->save();
        }
    }

    /**
     * Write a step into the container as an asset of its own.
     *
     * @param  array<string, mixed>  $tag
     */
    protected function write(AssetContainerContract $container, string $folder, string $name, string $bytes, array $tag): AssetContract
    {
        $info = getimagesizefromstring($bytes) ?: [];

        $extension = match ($info['mime'] ?? null) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = $this->freePath($container, $folder, Destinations::safeFilename($name, 'revision'), $extension);

        $container->disk()->put($path, $bytes);

        if (isset($info[0], $info[1])) {
            $tag['size'] = [(int) $info[0], (int) $info[1]];
        }

        $asset = $container->makeAsset($path);
        $asset->set(ThreadAssets::REVISION_KEY, $tag);
        $asset->save();

        return $asset;
    }

    /**
     * The revisions folder beside a saved image.
     */
    protected function folder(AssetContract $saved): string
    {
        $folder = trim((string) $saved->folder(), '/.');
        $name = $this->folderName();

        // Saved into a revisions folder itself (revised from a kept step):
        // its steps go beside it, not into another folder inside.
        return basename($folder) === $name ? $folder : ltrim($folder.'/'.$name, '/');
    }

    public function folderName(): string
    {
        return Destinations::safeFolder((string) ($this->config['revise']['history']['folder'] ?? 'revisions')) ?: 'revisions';
    }

    /**
     * A path nothing is using yet: a clash gets -2, -3 and so on, as an upload would.
     */
    protected function freePath(AssetContainerContract $container, string $folder, string $name, string $extension): string
    {
        $path = "{$folder}/{$name}.{$extension}";

        for ($n = 2; $container->disk()->exists($path); $n++) {
            $path = "{$folder}/{$name}-{$n}.{$extension}";
        }

        return $path;
    }
}
