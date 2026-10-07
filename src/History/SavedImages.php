<?php

namespace D3Creative\Darkroom\History;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\References\ReferenceStore;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Preference;

/**
 * The images Darkroom has saved, newest first.
 *
 * There is no separate history to keep in step. When an image is saved, the
 * prompt and settings that produced it are written onto the asset itself
 * under a "darkroom" key, and this simply finds the assets that have one. So
 * the history follows the assets wherever they are synced, and deleting an
 * asset removes it from the list. Moving one to the trash marks it on the asset
 * in the same way (see Trash).
 */
class SavedImages
{
    public const KEY = 'darkroom';

    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected ModelRegistry $models, protected array $config) {}

    /**
     * What to store on an asset so it can be found and its prompt reused.
     *
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public static function stamp(array $batch, ?array $thread = null): array
    {
        return array_filter([
            'prompt' => $batch['prompt'] ?? null,
            'model' => $batch['model'] ?? null,
            'quality' => $batch['quality'] ?? null,
            'aspect_ratio' => $batch['aspect_ratio'] ?? null,
            'instruction' => $batch['instruction_id'] ?? null,
            'instruction_title' => $batch['instruction_title'] ?? null,
            // The images sent with the prompt: names, and the asset for any
            // chosen from the library, so "Reuse prompt" can add them again.
            'references' => ($batch['references'] ?? null) ?: null,
            'upscaled_from' => $batch['upscaled_from'] ?? null,
            'revision' => ($batch['kind'] ?? null) === 'revise' ? ($batch['revision'] ?? null) : null,
            // The line of rounds that made a revised image (see Threads).
            'thread' => $thread,
            'generated_at' => $batch['created_at'] ?? null,
            'user' => $batch['user'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A list of reference records as the page shows it, whatever shape the
     * stored data is in.
     *
     * @return array<int, array{type: string, name: string, asset: string|null}>
     */
    public static function references(mixed $records): array
    {
        return collect(is_array($records) ? $records : [])
            ->filter(fn ($record) => is_array($record) && is_string($record['name'] ?? null))
            ->map(fn (array $record) => [
                'type' => ($record['type'] ?? null) === 'asset' ? 'asset' : 'upload',
                // Names stored before they were cleaned on the way in.
                'name' => ReferenceStore::safeName($record['name']),
                'asset' => is_string($record['asset'] ?? null) ? $record['asset'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The user preference that holds how many images History shows a page,
     * set by core's Per Page menu in the same way as its own listings.
     */
    public const PER_PAGE_PREFERENCE = 'darkroom.history.per_page';

    /**
     * "total" counts what matches the search; "all" counts every saved image,
     * search or not, which is the number the tab shows. "meta" is shaped like
     * a Laravel resource's, which is what core's Pagination component reads.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, all: int, meta: array<string, int>}
     */
    public function page($user, int $page = 1, ?string $search = null, ?int $perPage = null): array
    {
        $perPage = $this->perPage($perPage ?? Preference::get(self::PER_PAGE_PREFERENCE));
        $search = trim((string) $search);

        // Statamic queries assets one container at a time, so each container
        // the user may look at is asked in turn and the results merged.
        $all = AssetContainer::all()
            ->filter(fn ($container) => $user && Gate::forUser($user)->allows('view', $container))
            ->flatMap(fn ($container) => $container->queryAssets()->whereNotNull(self::KEY)->get()->all())
            // Trashed images are listed in the Trash tab instead.
            ->reject(fn (AssetContract $asset) => Trash::isTrashed($asset))
            ->values();

        $assets = $all
            ->when($search !== '', fn ($assets) => $assets->filter(fn (AssetContract $asset) => $this->matches($asset, $search)))
            ->sortByDesc(fn (AssetContract $asset) => (int) ($asset->get(self::KEY)['generated_at'] ?? 0))
            ->values();

        $total = $assets->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        // A page past the end, after deleting images or showing more per
        // page, lands on the last page rather than an empty one.
        $page = min(max(1, $page), $lastPage);
        $items = $assets->forPage($page, $perPage)->map(fn ($asset) => $this->present($asset))->values()->all();

        return [
            'items' => $items,
            'total' => $total,
            'all' => $all->count(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'from' => $items ? ($page - 1) * $perPage + 1 : 0,
                'to' => ($page - 1) * $perPage + count($items),
                'total' => $total,
            ],
        ];
    }

    /**
     * One of the page sizes the Control Panel offers everywhere else, so the
     * Per Page menu always has the current value in it. Anything else falls
     * back to the Control Panel's default.
     */
    protected function perPage(mixed $requested): int
    {
        $default = (int) config('statamic.cp.pagination_size', 50);
        $allowed = [$default, ...array_map('intval', (array) config('statamic.cp.pagination_size_options', []))];

        return in_array((int) $requested, $allowed, true) ? (int) $requested : max(1, $default);
    }

    /**
     * Whether every word searched for appears somewhere in the prompt, the
     * file's path, its alt text, the system instruction's title or the names
     * of its reference images, so "lighthouse dusk" finds "A lighthouse at
     * dusk" and a folder name finds everything saved in it.
     */
    protected function matches(AssetContract $asset, string $search): bool
    {
        $stamp = (array) $asset->get(self::KEY);

        $haystack = mb_strtolower(implode(' ', [
            $stamp['prompt'] ?? '',
            $asset->path(),
            $asset->get('alt') ?? '',
            $stamp['instruction_title'] ?? '',
            ...array_column(self::references($stamp['references'] ?? null), 'name'),
        ]));

        return collect(preg_split('/\s+/', mb_strtolower($search)))
            ->every(fn (string $word) => str_contains($haystack, $word));
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(AssetContract $asset): array
    {
        $stamp = (array) $asset->get(self::KEY);
        $folder = trim((string) $asset->folder(), '/.');

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'container' => $asset->containerHandle(),
            'folder' => $folder,
            'fileType' => strtolower((string) $asset->extension()),
            'alt' => $asset->get('alt'),
            'width' => $asset->width(),
            'height' => $asset->height(),
            'thumbnail' => $asset->thumbnailUrl('small'),
            'editUrl' => $asset->editUrl(),
            'prompt' => $stamp['prompt'] ?? '',
            'model' => $stamp['model'] ?? null,
            'modelLabel' => isset($stamp['model']) ? $this->models->label($stamp['model']) : null,
            'quality' => $stamp['quality'] ?? null,
            'qualityLabel' => ModelRegistry::qualityLabel($stamp['quality'] ?? null),
            'upscaledFrom' => ModelRegistry::qualityLabel($stamp['upscaled_from'] ?? null),
            'revision' => $stamp['revision'] ?? null,
            'thread' => is_string($stamp['thread']['id'] ?? null) && BatchStore::validId($stamp['thread']['id']) ? $stamp['thread']['id'] : null,
            'rounds' => is_array($stamp['thread']['rounds'] ?? null) ? count($stamp['thread']['rounds']) : 0,
            // Large enough to pin notes on, which core's thumbnails are not.
            'preview' => cp_route('darkroom.assets.preview', ['asset' => $asset->id()]),
            'aspectRatio' => $stamp['aspect_ratio'] ?? null,
            'instruction' => $stamp['instruction'] ?? null,
            'instructionTitle' => $stamp['instruction_title'] ?? null,
            'references' => self::references($stamp['references'] ?? null),
            'generatedAt' => $stamp['generated_at'] ?? null,
        ];
    }
}
