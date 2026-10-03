<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;

/**
 * Finds the assets that belong to a revision thread by what their stamps
 * say, never by a stored id. An asset's id is its path, so it changes when
 * the asset is moved or renamed, but its stamp travels with it in its meta.
 *
 * Three kinds of asset belong to a thread:
 * - a saved round: its darkroom stamp's last round is the round it is;
 * - a revision asset (see RevisionHistory): a copy of an earlier step, tagged
 *   with the round it shows;
 * - the original the first round started from, when it was already an asset:
 *   tagged with the threads that started from it.
 */
class ThreadAssets
{
    // On a revision asset: {thread, round (a round id or "origin"), step,
    // saved (the saved rounds whose history includes it), and what made it}.
    public const REVISION_KEY = 'darkroom_revision';

    // On an original that was already an asset: {thread: [saved round ids]}.
    // Kept apart from the darkroom stamp, so an image Darkroom did not make
    // does not start appearing in History.
    public const ORIGIN_KEY = 'darkroom_origin';

    /**
     * Every container is asked, whatever the viewer may see: callers decide
     * what to show. Like History, this reads the meta of every asset Darkroom
     * has stamped, which is fine at hundreds of images.
     *
     * @return array{rounds: array<string, AssetContract>, origin: ?AssetContract}
     */
    public function find(string $thread): array
    {
        $rounds = [];
        $origin = null;

        if (! BatchStore::validId($thread)) {
            return ['rounds' => $rounds, 'origin' => $origin];
        }

        foreach ($this->stamped(SavedImages::KEY) as $asset) {
            $stamp = (array) $asset->get(SavedImages::KEY);

            if (($round = self::savedRound($stamp)) && ($stamp['thread']['id'] ?? null) === $thread) {
                $rounds[$round] ??= $asset;
            }
        }

        $origin = $this->origins($thread)[0] ?? null;

        // A round saved as an image of its own comes before a copy of it.
        foreach ($this->revisions($thread) as $asset) {
            $round = ((array) $asset->get(self::REVISION_KEY))['round'];

            if ($round === 'origin') {
                $origin ??= $asset;
            } else {
                $rounds[$round] ??= $asset;
            }
        }

        return ['rounds' => $rounds, 'origin' => $origin];
    }

    /**
     * Every revision asset of a thread.
     *
     * @return array<int, AssetContract>
     */
    public function revisions(string $thread): array
    {
        return array_values(array_filter($this->stamped(self::REVISION_KEY), function (AssetContract $asset) use ($thread) {
            $tag = (array) $asset->get(self::REVISION_KEY);

            return ($tag['thread'] ?? null) === $thread && is_string($tag['round'] ?? null);
        }));
    }

    /**
     * Every asset tagged as an original of the thread.
     *
     * @return array<int, AssetContract>
     */
    public function origins(string $thread): array
    {
        return array_values(array_filter(
            $this->stamped(self::ORIGIN_KEY),
            fn (AssetContract $asset) => array_key_exists($thread, (array) $asset->get(self::ORIGIN_KEY)),
        ));
    }

    /**
     * The round a saved image is: the last one in the line stamped on it.
     *
     * @param  array<string, mixed>  $stamp  The asset's darkroom stamp.
     */
    public static function savedRound(array $stamp): ?string
    {
        $rounds = $stamp['thread']['rounds'] ?? null;

        if (! is_array($rounds) || $rounds === []) {
            return null;
        }

        $last = end($rounds);
        $id = is_array($last) ? ($last['id'] ?? null) : null;

        return is_string($id) && BatchStore::validId($id) ? $id : null;
    }

    /**
     * Every asset, in every container, that has the given key.
     *
     * @return array<int, AssetContract>
     */
    protected function stamped(string $key): array
    {
        return AssetContainer::all()
            ->flatMap(fn ($container) => $container->queryAssets()->whereNotNull($key)->get()->all())
            ->values()
            ->all();
    }
}
