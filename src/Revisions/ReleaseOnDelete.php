<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetDeleting;

/**
 * When a saved revised image is deleted, anywhere (Darkroom's Trash, its
 * 30-day purge or core's asset browser), its history is released, so steps
 * no other image needs go with it (see RevisionHistory::release).
 *
 * The stamp has to be read before the deletion: by the time AssetDeleted
 * fires, the asset's meta file is gone and its data cannot be loaded. So
 * AssetDeleting notes which thread and round the image was (and copies it
 * for any other image whose story shows it), and AssetDeleted acts on it
 * only once the deletion has actually happened.
 */
class ReleaseOnDelete
{
    /** @var array<string, array{0: string, 1: string}> Asset id => [thread, round]. */
    protected static array $pending = [];

    public function deleting(AssetDeleting $event): void
    {
        $stamp = (array) $event->asset->get(SavedImages::KEY);
        $thread = $stamp['thread']['id'] ?? null;

        if (is_string($thread) && BatchStore::validId($thread) && ($round = ThreadAssets::savedRound($stamp))) {
            self::$pending[$event->asset->id()] = [$thread, $round];
        }

        // Other saved images' stories may show this one as an earlier step,
        // so a copy is kept for them while the file still exists.
        try {
            app(RevisionHistory::class)->preserve($event->asset);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function deleted(AssetDeleted $event): void
    {
        $id = $event->asset->id();
        $pending = self::$pending[$id] ?? null;

        unset(self::$pending[$id]);

        if (! $pending) {
            return;
        }

        // Never the reason a deletion fails.
        try {
            app(RevisionHistory::class)->release(...$pending);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
