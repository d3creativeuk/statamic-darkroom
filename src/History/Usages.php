<?php

namespace D3Creative\Darkroom\History;

use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\Variables as VariablesContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Listeners\Concerns\GetsItemsContainingData;

/**
 * Where images are used on the site, so deleting one never breaks a page
 * without someone choosing that.
 *
 * It reads the same content Statamic walks when an asset is renamed: entries,
 * terms, globals and users. An Assets field stores the path, and Bard, Link
 * and Markdown store "asset::container::path", so both are looked for, along
 * with the file's URL for links typed by hand. It cannot see an image written
 * into a template or linked from another site.
 */
class Usages
{
    use GetsItemsContainingData;

    /**
     * Every place each asset appears, keyed by asset id. Content is read once,
     * however many assets are asked about.
     *
     * @param  array<int, AssetContract>  $assets
     * @param  mixed  $user  Who is asking. Places they may not open have no link.
     * @return array<string, array<int, array{type: string, title: string, url: ?string}>>
     */
    public function find(array $assets, $user = null): array
    {
        $needles = collect($assets)->mapWithKeys(fn (AssetContract $asset) => [
            $asset->id() => array_values(array_filter([
                'asset::'.$asset->id(),
                json_encode($asset->path(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $asset->url() ? json_encode($asset->url(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $asset->url() ? '('.$asset->url().')' : null,
            ])),
        ]);

        $found = $needles->map(fn () => [])->all();

        if ($needles->isEmpty()) {
            return $found;
        }

        $this->getItemsContainingData()->each(function ($item) use ($needles, &$found, $user) {
            $data = method_exists($item, 'data') ? $item->data() : [];
            $haystack = json_encode($data instanceof \Illuminate\Support\Collection ? $data->all() : $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! $haystack) {
                return;
            }

            foreach ($needles as $id => $candidates) {
                foreach ($candidates as $needle) {
                    if (str_contains($haystack, $needle)) {
                        $found[$id][] = $this->describe($item, $user);

                        break;
                    }
                }
            }
        });

        return $found;
    }

    /**
     * @return array{type: string, title: string, url: ?string}
     */
    protected function describe($item, $user): array
    {
        [$type, $title] = match (true) {
            $item instanceof EntryContract => [__('Entry'), (string) ($item->value('title') ?? $item->slug() ?? $item->id())],
            $item instanceof TermContract => [__('Term'), (string) $item->title()],
            $item instanceof VariablesContract => [__('Global'), (string) $item->globalSet()->title()],
            $item instanceof UserContract => [__('User'), (string) ($item->name() ?: $item->email())],
            default => [__('Content'), (string) (method_exists($item, 'id') ? $item->id() : '')],
        };

        $canOpen = $user && method_exists($item, 'editUrl') && rescue(fn () => Gate::forUser($user)->allows('view', $item), false, false);

        return ['type' => $type, 'title' => $title, 'url' => $canOpen ? $item->editUrl() : null];
    }
}
