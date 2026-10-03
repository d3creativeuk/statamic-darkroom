<?php

namespace D3Creative\Darkroom\History;

use D3Creative\Darkroom\Revisions\RevisionHistory;
use D3Creative\Darkroom\Revisions\ThreadAssets;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;

/**
 * Images taken out of History, waiting to be deleted.
 *
 * Like History itself there is no separate store. Moving an image to the
 * trash only adds "trashed_at" to the darkroom key on its asset, so nothing
 * moves and the asset keeps working wherever it is used until it is actually
 * deleted: by hand from the Trash tab, or once it has been there for the
 * retention period. That clean-up never deletes an image that is still used
 * somewhere; it only stops Darkroom listing it.
 */
class Trash
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected Usages $usages, protected array $config) {}

    public static function isTrashed(AssetContract $asset): bool
    {
        return isset(((array) $asset->get(SavedImages::KEY))['trashed_at']);
    }

    public function retentionDays(): int
    {
        return max(1, (int) ($this->config['trash']['retention_days'] ?? 30));
    }

    /**
     * Everything in the trash that this user may view, most recently removed
     * first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list($user): array
    {
        return $this->trashed(fn ($container) => $user && Gate::forUser($user)->allows('view', $container))
            ->sortByDesc(fn (AssetContract $asset) => (int) $asset->get(SavedImages::KEY)['trashed_at'])
            ->map(fn (AssetContract $asset) => $this->present($asset))
            ->values()
            ->all();
    }

    public function move(AssetContract $asset, $user): void
    {
        $this->stamp($asset, [
            'trashed_at' => now()->getTimestamp(),
            'trashed_by' => $user ? (string) $user->id() : null,
        ]);
    }

    public function restore(AssetContract $asset): void
    {
        $this->stamp($asset, ['trashed_at' => null, 'trashed_by' => null]);
    }

    /**
     * Stop listing an image anywhere in Darkroom, leaving the asset itself
     * exactly where it is. Used for an image someone wants to keep because
     * the site uses it.
     */
    public function forget(AssetContract $asset): void
    {
        $stamp = (array) $asset->get(SavedImages::KEY);

        // Once unstamped it can no longer be found as a round, so any later
        // image's story that shows it keeps a copy first.
        try {
            app(RevisionHistory::class)->preserve($asset, asOriginal: false);
        } catch (\Throwable $e) {
            report($e);
        }

        $asset->remove(SavedImages::KEY)->save();

        // The steps kept for a revised image go with its place in Darkroom,
        // unless another image's story or the site still needs them.
        $thread = $stamp['thread']['id'] ?? null;

        if (is_string($thread) && ($round = ThreadAssets::savedRound($stamp))) {
            try {
                app(RevisionHistory::class)->release($thread, $round);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function destroy(AssetContract $asset): void
    {
        $asset->delete();
    }

    /**
     * Delete whatever has been in the trash longer than the retention period,
     * except images still used on the site, which are forgotten instead.
     *
     * @return array{deleted: int, kept: int}
     */
    public function purge(?int $days = null): array
    {
        $cutoff = now()->subDays($days ?? $this->retentionDays())->getTimestamp();

        $expired = $this->trashed()
            ->filter(fn (AssetContract $asset) => (int) $asset->get(SavedImages::KEY)['trashed_at'] <= $cutoff)
            ->values();

        if ($expired->isEmpty()) {
            return ['deleted' => 0, 'kept' => 0];
        }

        $usages = $this->usages->find($expired->all());
        $result = ['deleted' => 0, 'kept' => 0];

        foreach ($expired as $asset) {
            if ($usages[$asset->id()] ?? []) {
                $this->forget($asset);
                $result['kept']++;
            } else {
                $this->destroy($asset);
                $result['deleted']++;
            }
        }

        return $result;
    }

    /**
     * @param  callable|null  $containers  Which containers to look in. All of them by default.
     * @return \Illuminate\Support\Collection<int, AssetContract>
     */
    protected function trashed(?callable $containers = null)
    {
        $all = AssetContainer::all();

        // Not when(): it would call the filter itself to decide.
        if ($containers) {
            $all = $all->filter($containers);
        }

        return $all
            ->flatMap(fn ($container) => $container->queryAssets()->whereNotNull(SavedImages::KEY)->get()->all())
            ->filter(fn (AssetContract $asset) => self::isTrashed($asset))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $changes  A null value removes that key.
     */
    protected function stamp(AssetContract $asset, array $changes): void
    {
        $stamp = array_filter(
            array_merge((array) $asset->get(SavedImages::KEY), $changes),
            fn ($value) => $value !== null,
        );

        $asset->set(SavedImages::KEY, $stamp)->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(AssetContract $asset): array
    {
        $trashedAt = (int) $asset->get(SavedImages::KEY)['trashed_at'];

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'basename' => $asset->basename(),
            'container' => $asset->containerHandle(),
            'containerTitle' => $asset->container()->title(),
            'folder' => trim((string) $asset->folder(), '/.'),
            'alt' => $asset->get('alt'),
            'thumbnail' => $asset->thumbnailUrl('small'),
            'trashedAt' => $trashedAt,
            'deletesAt' => $trashedAt + $this->retentionDays() * 86400,
        ];
    }
}
