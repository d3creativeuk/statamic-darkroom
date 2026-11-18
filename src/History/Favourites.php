<?php

namespace D3Creative\Darkroom\History;

use Statamic\Contracts\Assets\Asset as AssetContract;

/**
 * Images someone has hearted, shown in their Favourites tab.
 *
 * Favourites are personal: each asset lists the ids of the users who hearted
 * it, under "favourites" in its darkroom key, the same way Trash marks an
 * image. So they follow the asset through a move or rename, and an image
 * forgotten by Darkroom or deleted takes its hearts with it.
 */
class Favourites
{
    public const FIELD = 'favourites';

    public static function has(AssetContract $asset, $user): bool
    {
        return $user && in_array((string) $user->id(), self::of($asset), true);
    }

    public function set(AssetContract $asset, $user, bool $favourite): void
    {
        $id = (string) $user->id();
        $users = array_values(array_diff(self::of($asset), [$id]));

        if ($favourite) {
            $users[] = $id;
        }

        $stamp = (array) $asset->get(SavedImages::KEY);

        if ($users) {
            $stamp[self::FIELD] = $users;
        } else {
            unset($stamp[self::FIELD]);
        }

        $asset->set(SavedImages::KEY, $stamp)->save();
    }

    /**
     * @return array<int, string>
     */
    protected static function of(AssetContract $asset): array
    {
        $users = ((array) $asset->get(SavedImages::KEY))[self::FIELD] ?? [];

        return is_array($users) ? array_map('strval', $users) : [];
    }
}
