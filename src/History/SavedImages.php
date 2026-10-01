<?php

namespace D3Creative\Darkroom\History;

use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;

/**
 * The images Darkroom has saved, newest first.
 *
 * There is no separate history to keep in step. When an image is saved, the
 * prompt and settings that produced it are written onto the asset itself
 * under a "darkroom" key, and this simply finds the assets that have one. So
 * the history follows the assets wherever they are synced, and deleting an
 * asset removes it from the list.
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
    public static function stamp(array $batch): array
    {
        return array_filter([
            'prompt' => $batch['prompt'] ?? null,
            'model' => $batch['model'] ?? null,
            'quality' => $batch['quality'] ?? null,
            'aspect_ratio' => $batch['aspect_ratio'] ?? null,
            'instruction' => $batch['instruction_id'] ?? null,
            'instruction_title' => $batch['instruction_title'] ?? null,
            'upscaled_from' => $batch['upscaled_from'] ?? null,
            'generated_at' => $batch['created_at'] ?? null,
            'user' => $batch['user'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * "total" counts what matches the search; "all" counts every saved image,
     * search or not, which is the number the tab shows.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, all: int, nextPage: int|null}
     */
    public function page($user, int $page = 1, ?string $search = null): array
    {
        $perPage = max(1, (int) ($this->config['history']['per_page'] ?? 12));
        $page = max(1, $page);
        $search = trim((string) $search);

        // Statamic queries assets one container at a time, so each container
        // the user may look at is asked in turn and the results merged.
        $all = AssetContainer::all()
            ->filter(fn ($container) => $user && Gate::forUser($user)->allows('view', $container))
            ->flatMap(fn ($container) => $container->queryAssets()->whereNotNull(self::KEY)->get()->all());

        $assets = $all
            ->when($search !== '', fn ($assets) => $assets->filter(fn (AssetContract $asset) => $this->matches($asset, $search)))
            ->sortByDesc(fn (AssetContract $asset) => (int) ($asset->get(self::KEY)['generated_at'] ?? 0))
            ->values();

        return [
            'items' => $assets->forPage($page, $perPage)->map(fn ($asset) => $this->present($asset))->values()->all(),
            'total' => $assets->count(),
            'all' => $all->count(),
            'nextPage' => $assets->count() > $page * $perPage ? $page + 1 : null,
        ];
    }

    /**
     * Whether every word searched for appears somewhere in the prompt, the
     * file's path, its alt text or the system instruction's title, so
     * "lighthouse dusk" finds "A lighthouse at dusk" and a folder name finds
     * everything saved in it.
     */
    protected function matches(AssetContract $asset, string $search): bool
    {
        $stamp = (array) $asset->get(self::KEY);

        $haystack = mb_strtolower(implode(' ', [
            $stamp['prompt'] ?? '',
            $asset->path(),
            $asset->get('alt') ?? '',
            $stamp['instruction_title'] ?? '',
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
            'aspectRatio' => $stamp['aspect_ratio'] ?? null,
            'instruction' => $stamp['instruction'] ?? null,
            'instructionTitle' => $stamp['instruction_title'] ?? null,
            'generatedAt' => $stamp['generated_at'] ?? null,
        ];
    }
}
