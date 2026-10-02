<?php

namespace D3Creative\Darkroom\Assets;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;

/**
 * Where a generated image is allowed to go: the asset containers a user may
 * upload to, and the folders inside them.
 */
class Destinations
{
    /**
     * @return Collection<int, AssetContainerContract>
     */
    public function containersFor($user): Collection
    {
        return AssetContainer::all()
            ->filter(fn ($container) => $this->allows($user, $container))
            ->values();
    }

    public function find($user, ?string $handle): ?AssetContainerContract
    {
        $container = $handle ? AssetContainer::findByHandle($handle) : null;

        return $container && $this->allows($user, $container) ? $container : null;
    }

    public function allows($user, AssetContainerContract $container): bool
    {
        return $user && Gate::forUser($user)->allows('store', [AssetContract::class, $container]);
    }

    /**
     * Every folder in the container, as paths without slashes at either end,
     * in natural order. Read fresh each time, so a folder created a moment ago
     * in the asset browser is included.
     *
     * @return array<int, string>
     */
    public function folders(AssetContainerContract $container): array
    {
        return $container->folders()
            ->map(fn ($path) => trim((string) $path, '/'))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Each container, with what Statamic's own asset browser needs to show it.
     * The folder picker is that browser, so this is built exactly the way the
     * core Assets fieldtype builds it for its selector.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forFrontend($user): array
    {
        return $this->containersFor($user)->map(function ($container) {
            $preload = (new Field('darkroom_destination', [
                'type' => 'assets',
                'container' => $container->handle(),
                'max_files' => 1,
            ]))->fieldtype()->preload();

            return [
                'handle' => $container->handle(),
                'title' => $container->title(),
                // Uploading is switched off. The picker is for choosing a
                // folder, and a file dropped on it by accident would upload.
                'browser' => array_merge($preload['container'], ['can_upload' => false]),
                'columns' => $preload['columns'],
            ];
        })->all();
    }

    /**
     * Validation for a folder sent from the page. safeFolder() keeps ".."
     * segments, and although the filesystem refuses a path that climbs out of
     * the container, that refusal only surfaces once the save has failed.
     */
    public static function folderRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $value)) {
                $fail('Choose a folder inside the container.');
            }
        };
    }

    /**
     * A folder path made safe the same way Statamic does for uploads. A folder
     * that does not exist yet is created by the upload.
     */
    public static function safeFolder(?string $folder): string
    {
        return trim((string) AssetUploader::getSafePath(trim((string) $folder, '/')), '/');
    }

    /**
     * A filename without an extension, made safe for a URL. Dots are replaced
     * so something typed as "hero.final" cannot be read as an extension.
     */
    public static function safeFilename(?string $filename, string $fallback = 'darkroom'): string
    {
        $safe = AssetUploader::getSafeFilename(str_replace('.', '-', trim((string) $filename)));
        $safe = trim(Str::limit($safe, 120, ''), '-');

        return $safe !== '' ? $safe : $fallback;
    }
}
