<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Trash;
use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;

/**
 * Rounds of revising one image, kept together as a thread.
 *
 * A round is a revise batch of one, and its id is the batch id. Each round
 * carries a copy of the rounds that led to it, so the thread survives the
 * batches it came from being pruned after a day. When a round is saved, that
 * line of rounds is stamped onto the asset, so reopening it from History
 * brings the feed back. Branches that were tried and left are not stamped:
 * they are not how that image was made.
 */
class Threads
{
    public const MAX_ROUNDS = 20;

    public function __construct(
        protected BatchStore $store,
        protected ModelRegistry $models,
        protected BatchPresenter $presenter,
    ) {}

    /**
     * The thread a new round joins, given the image it starts from (as found
     * by FindsSourceImages): the thread of a round, the thread stamped on a
     * saved round, or a new one.
     *
     * @param  array<string, mixed>  $source
     * @return array{id: string, parent: ?string, origin: ?array<string, mixed>, ancestors: array<int, array<string, mixed>>}
     */
    public function next(array $source): array
    {
        $base = $source['base']['batch'] ?? null;

        if ($base && ($id = BatchStore::threadOf($base))) {
            return [
                'id' => $id,
                'parent' => $base['id'],
                'origin' => $this->origin($base['thread']['origin'] ?? null),
                'ancestors' => $this->cap([...$this->rounds($base['thread']['ancestors'] ?? []), $this->summary($base)]),
            ];
        }

        if ($base) {
            return $this->start(['batch' => $base['id'], 'index' => (int) $source['base']['item']['index']]);
        }

        $asset = $source['asset'] ?? null;
        $stamped = $this->stamped($source['stamp'] ?? [], $asset);

        if ($stamped) {
            return [
                'id' => $stamped['id'],
                'parent' => $stamped['rounds'][array_key_last($stamped['rounds'])]['id'],
                'origin' => $stamped['origin'],
                'ancestors' => $stamped['rounds'],
            ];
        }

        return $this->start($asset ? ['asset' => $asset->id()] : null);
    }

    /**
     * One round as it is remembered by the rounds after it.
     *
     * @param  array<string, mixed>  $batch  A revise batch, as BatchStore::find returns it.
     * @return array<string, mixed>
     */
    public function summary(array $batch): array
    {
        $item = $batch['items'][0] ?? [];

        return array_filter([
            'id' => $batch['id'],
            'parent' => $batch['thread']['parent'] ?? null,
            'notes' => array_values($batch['revision']['notes'] ?? []),
            'general' => $batch['revision']['general'] ?? null,
            'model' => $batch['model'] ?? null,
            'quality' => $batch['quality'] ?? null,
            'at' => (int) ($batch['created_at'] ?? 0),
            'interaction' => $item['interaction'] ?? null,
            'memory' => $item['memory'] ?? null,
            'asset' => $item['asset']['id'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * What is stamped onto the asset when a round is saved: the line of rounds
     * that made it, this one last. Earlier rounds and the original pick up
     * the assets they were saved as since, where their batches still say.
     *
     * @param  array<string, mixed>  $batch
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function trail(array $batch, array $item): ?array
    {
        if (! ($id = BatchStore::threadOf($batch))) {
            return null;
        }

        $rounds = array_map(fn (array $round) => $this->withAsset($round), $this->rounds($batch['thread']['ancestors'] ?? []));

        // This image's own entry. Its asset id is not known until it has been
        // saved, so the feed takes it from the asset the stamp is read from.
        $own = $this->summary($batch);
        unset($own['asset']);

        if (isset($item['width'], $item['height'])) {
            $own['size'] = [(int) $item['width'], (int) $item['height']];
        }

        return array_filter([
            'id' => $id,
            'origin' => $this->savedOrigin($this->origin($batch['thread']['origin'] ?? null)),
            'rounds' => $this->cap([...$rounds, $own]),
        ], fn ($value) => $value !== null);
    }

    /**
     * Everything the Revise panel shows for a thread: where it started and
     * every round, oldest first. Rounds come from the trail stamped on the
     * asset it was opened from, the copies carried by later rounds, and the
     * user's own rounds still in temporary storage, which win.
     *
     * @return array<string, mixed>|null Null when nothing is known about the thread.
     */
    public function feed($user, string $threadId, ?AssetContract $asset = null): ?array
    {
        if (! BatchStore::validId($threadId)) {
            return null;
        }

        $live = $this->store->threadFor((string) $user->id(), $threadId);
        $rounds = [];
        $origin = null;

        $stamp = $asset ? (array) $asset->get(SavedImages::KEY) : [];
        $stamped = $asset ? $this->stamped($stamp, $asset) : null;
        $prompt = $live[0]['prompt'] ?? null;

        if ($stamped && $stamped['id'] === $threadId) {
            $prompt ??= $stamp['prompt'] ?? null;

            foreach ($stamped['rounds'] as $round) {
                $rounds[$round['id']] = $round;
            }

            $origin = $stamped['origin'];
        }

        foreach ($live as $batch) {
            foreach ($this->rounds($batch['thread']['ancestors'] ?? []) as $round) {
                $rounds[$round['id']] ??= $round;
            }

            $origin ??= $this->origin($batch['thread']['origin'] ?? null);
        }

        foreach ($live as $batch) {
            $rounds[$batch['id']] = ['live' => $batch] + $this->summary($batch);
        }

        if ($rounds === []) {
            return null;
        }

        uasort($rounds, fn ($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        return [
            'id' => $threadId,
            'prompt' => $prompt,
            'origin' => $this->presentOrigin($origin, $user),
            'rounds' => array_values(array_map(fn (array $round) => $this->presentRound($round, $user), $rounds)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>
     */
    protected function start(?array $origin): array
    {
        return ['id' => strtolower((string) Str::ulid()), 'parent' => null, 'origin' => $origin, 'ancestors' => []];
    }

    /**
     * The thread stamped on a saved round, checked, with that round's asset
     * filled in. Null when there is none or it does not hold together.
     *
     * @param  array<string, mixed>  $stamp
     * @return array{id: string, origin: ?array<string, mixed>, rounds: array<int, array<string, mixed>>}|null
     */
    protected function stamped(array $stamp, ?AssetContract $asset): ?array
    {
        $thread = $stamp['thread'] ?? null;
        $id = is_array($thread) ? ($thread['id'] ?? null) : null;

        if (! is_string($id) || ! BatchStore::validId($id)) {
            return null;
        }

        $rounds = $this->rounds($thread['rounds'] ?? []);

        if ($rounds === []) {
            return null;
        }

        if ($asset) {
            $rounds[array_key_last($rounds)]['asset'] = $asset->id();
        }

        return ['id' => $id, 'origin' => $this->origin($thread['origin'] ?? null), 'rounds' => $this->cap($rounds)];
    }

    /**
     * Rounds read back from a batch or an asset's YAML, reduced to what is
     * known to be well formed. Anything else is dropped rather than trusted.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rounds(mixed $rounds): array
    {
        if (! is_array($rounds)) {
            return [];
        }

        $clean = [];

        foreach ($rounds as $round) {
            if (! is_array($round) || ! is_string($round['id'] ?? null) || ! BatchStore::validId($round['id'])) {
                continue;
            }

            $notes = array_values(array_filter(array_map(fn ($note) => is_array($note) && is_string($note['text'] ?? null)
                ? ['x' => (float) ($note['x'] ?? 0), 'y' => (float) ($note['y'] ?? 0), 'text' => Str::limit($note['text'], 500, '')]
                : null, is_array($round['notes'] ?? null) ? $round['notes'] : [])));

            $clean[] = array_filter([
                'id' => $round['id'],
                'parent' => is_string($round['parent'] ?? null) && BatchStore::validId($round['parent']) ? $round['parent'] : null,
                'notes' => $notes,
                'general' => is_string($round['general'] ?? null) ? Str::limit($round['general'], 2000, '') : null,
                'model' => is_string($round['model'] ?? null) ? $round['model'] : null,
                'quality' => is_scalar($round['quality'] ?? null) ? (string) $round['quality'] : null,
                'at' => (int) ($round['at'] ?? 0),
                'interaction' => is_string($round['interaction'] ?? null) ? $round['interaction'] : null,
                'memory' => in_array($round['memory'] ?? null, ['continued', 'lost'], true) ? $round['memory'] : null,
                'asset' => is_string($round['asset'] ?? null) ? $round['asset'] : null,
                'size' => is_array($round['size'] ?? null) && count($round['size']) === 2 ? array_map('intval', array_values($round['size'])) : null,
            ], fn ($value) => $value !== null);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function origin(mixed $origin): ?array
    {
        if (! is_array($origin)) {
            return null;
        }

        if (is_string($origin['asset'] ?? null)) {
            return ['asset' => $origin['asset']];
        }

        if (is_string($origin['batch'] ?? null) && BatchStore::validId($origin['batch'])) {
            return ['batch' => $origin['batch'], 'index' => max(1, (int) ($origin['index'] ?? 1))];
        }

        return null;
    }

    /**
     * Only an original that became an asset is worth stamping: an unsaved
     * one is gone within a day.
     *
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>|null
     */
    protected function savedOrigin(?array $origin): ?array
    {
        if (isset($origin['asset'])) {
            return $origin;
        }

        $asset = isset($origin['batch']) ? ($this->store->item($origin['batch'], $origin['index'])['asset']['id'] ?? null) : null;

        return $asset ? ['asset' => $asset] : null;
    }

    /**
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    protected function withAsset(array $round): array
    {
        if (! isset($round['asset']) && ($asset = $this->store->item($round['id'], 1)['asset']['id'] ?? null)) {
            $round['asset'] = $asset;
        }

        return $round;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rounds
     * @return array<int, array<string, mixed>>
     */
    protected function cap(array $rounds): array
    {
        return array_values(array_slice($rounds, -self::MAX_ROUNDS));
    }

    /**
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    protected function presentRound(array $round, $user): array
    {
        $batch = $round['live'] ?? null;
        $model = $round['model'] ?? null;
        $quality = $round['quality'] ?? null;

        return [
            'id' => $round['id'],
            'parent' => $round['parent'] ?? null,
            'at' => $round['at'],
            'notes' => $round['notes'] ?? [],
            'general' => $round['general'] ?? null,
            'model' => $model,
            'modelLabel' => $model ? $this->models->label($model) : null,
            'quality' => $quality,
            'qualityLabel' => ModelRegistry::qualityLabel($quality),
            'price' => $model && $quality ? $this->models->price($model, $quality) : null,
            'memory' => $round['memory'] ?? null,
            // The round as the working area sees it, while it is still in
            // temporary storage, so its status and actions stay live.
            'batch' => $batch ? $this->presenter->present($batch) : null,
            'asset' => $this->presentAsset($round['asset'] ?? null, $user),
        ];
    }

    /**
     * The image the thread started from, with a preview while one exists.
     *
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>|null
     */
    protected function presentOrigin(?array $origin, $user): ?array
    {
        if (! $origin) {
            return null;
        }

        $presented = ['asset' => null, 'batch' => null, 'index' => null, 'image' => null];

        if (isset($origin['asset'])) {
            $presented['asset'] = $this->presentAsset($origin['asset'], $user);
            $presented['image'] = $presented['asset']['preview'] ?? null;
        } elseif (($batch = $this->store->find($origin['batch'])) && ($batch['user'] ?? null) === (string) $user->id()) {
            $item = collect($batch['items'])->firstWhere('index', $origin['index']);

            $presented['batch'] = $batch['id'];
            $presented['index'] = $origin['index'];
            $presented['model'] = $batch['model'];
            $presented['quality'] = $batch['quality'];
            $presented['aspectRatio'] = $batch['aspect_ratio'];

            if (($item['status'] ?? null) === ItemStatus::Complete->value && $this->store->hasPreview($batch['id'], $origin['index'])) {
                $presented['image'] = cp_route('darkroom.items.preview', [$batch['id'], $origin['index']]).'?v='.($item['updated_at'] ?? 0);
            } elseif (isset($item['asset']['id'])) {
                $presented['asset'] = $this->presentAsset($item['asset']['id'], $user);
                $presented['image'] = $presented['asset']['preview'] ?? null;
            }
        }

        return $presented;
    }

    /**
     * An asset only if this user may see it and it is not in the trash.
     *
     * @return array{id: string, path: string, preview: string}|null
     */
    protected function presentAsset(?string $id, $user): ?array
    {
        $asset = $id ? Asset::find($id) : null;

        if (! $asset || Trash::isTrashed($asset) || ! Gate::forUser($user)->allows('view', $asset)) {
            return null;
        }

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'preview' => cp_route('darkroom.assets.preview', ['asset' => $asset->id()]),
        ];
    }
}
