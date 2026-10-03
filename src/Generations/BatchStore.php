<?php

namespace D3Creative\Darkroom\Generations;

use D3Creative\Darkroom\Support\AtomicFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generated images waiting to be saved or discarded, kept outside the web
 * root. One directory per batch:
 *
 *   batch.json            what was asked for; written once
 *   item-1.json           that image's status; rewritten as it progresses
 *   item-1.orig           the bytes Google returned
 *   item-1.preview.jpg    a smaller copy for the page
 *
 * Each image has its own status file so that two images finishing, or being
 * saved, at the same moment in different PHP workers never overwrite each
 * other's progress.
 */
class BatchStore
{
    // A save has to warm every Glide preset on a sync queue, so it gets far
    // longer than a generation before it is assumed dead.
    protected const SAVE_STALE_AFTER = 900;

    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-z]{26}$/', $id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes, int $count): array
    {
        $id = strtolower((string) Str::ulid());

        $this->writeJson("{$id}/batch.json", array_merge($attributes, [
            'id' => $id,
            'count' => $count,
            'created_at' => now()->getTimestamp(),
        ]));

        for ($index = 1; $index <= $count; $index++) {
            $this->writeItem($id, $this->blankItem($index));
        }

        return $this->find($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! self::validId($id) || ! ($batch = $this->readJson("{$id}/batch.json"))) {
            return null;
        }

        $batch['items'] = [];

        for ($index = 1; $index <= (int) $batch['count']; $index++) {
            if ($item = $this->readJson("{$id}/item-{$index}.json")) {
                $batch['items'][] = $this->expire($id, $item);
            }
        }

        return $batch;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $id, int $index): ?array
    {
        if (! self::validId($id) || ! ($item = $this->readJson("{$id}/item-{$index}.json"))) {
            return null;
        }

        return $this->expire($id, $item);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    public function updateItem(string $id, int $index, array $changes): ?array
    {
        if (! ($item = $this->readJson("{$id}/item-{$index}.json"))) {
            return null;
        }

        $item = array_merge($item, $changes, ['updated_at' => now()->getTimestamp()]);

        $this->writeItem($id, $item);

        return $item;
    }

    /**
     * Put an image back to the start so it can be generated again.
     */
    public function resetItem(string $id, int $index): void
    {
        $this->deleteItemFiles($id, $index);
        $this->writeItem($id, $this->blankItem($index));
    }

    /**
     * Keep a copy of the image a batch is upscaling. Copied in when the batch
     * is created, so discarding or pruning the original meanwhile cannot leave
     * the job with nothing to send.
     */
    public function putSource(string $id, string $binary): void
    {
        $this->disk()->put($this->path("{$id}/source.bin"), $binary);
    }

    public function source(string $id): ?string
    {
        return $this->disk()->get($this->path("{$id}/source.bin"));
    }

    public function putOriginal(string $id, int $index, string $binary): void
    {
        $this->disk()->put($this->path("{$id}/item-{$index}.orig"), $binary);
    }

    public function original(string $id, int $index): ?string
    {
        return $this->disk()->get($this->path("{$id}/item-{$index}.orig"));
    }

    public function putPreview(string $id, int $index, string $jpeg): void
    {
        $this->disk()->put($this->previewPath($id, $index), $jpeg);
    }

    /**
     * Path of the preview on the temp disk, whether or not it exists yet.
     */
    public function previewPath(string $id, int $index): string
    {
        return $this->path("{$id}/item-{$index}.preview.jpg");
    }

    public function hasPreview(string $id, int $index): bool
    {
        return $this->disk()->exists($this->previewPath($id, $index));
    }

    public function deleteOriginal(string $id, int $index): void
    {
        $this->disk()->delete($this->path("{$id}/item-{$index}.orig"));
    }

    public function deleteItemFiles(string $id, int $index): void
    {
        $this->disk()->delete([
            $this->path("{$id}/item-{$index}.orig"),
            $this->previewPath($id, $index),
        ]);
    }

    public function delete(string $id): void
    {
        if (self::validId($id)) {
            $this->disk()->deleteDirectory($this->path($id));
        }
    }

    /**
     * The user's batches that still have something to show: images being
     * generated or saved, or finished ones nobody has dealt with. Newest
     * first. This is what lets a page reload pick up where it left off.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openFor(string $userId, int $limit = 10): array
    {
        $open = [];

        // A revision thread shows as one batch: its newest round that was not
        // discarded or failed, and nothing once that round is saved, because
        // it is in History then. Batches come newest first, so the first
        // round that settles a thread decides it.
        $settled = [];

        foreach ($this->ids() as $id) {
            $batch = $this->find($id);

            if (! $batch || ($batch['user'] ?? null) !== $userId) {
                continue;
            }

            if ($thread = self::threadOf($batch)) {
                $status = $batch['items'][0]['status'] ?? null;

                if (isset($settled[$thread]) || in_array($status, [ItemStatus::Discarded->value, ItemStatus::Failed->value], true)) {
                    continue;
                }

                $settled[$thread] = true;

                if ($status !== ItemStatus::Saved->value) {
                    $open[] = $batch;
                }
            } elseif ($this->has($batch, fn (ItemStatus $s) => $s->isOpen())) {
                $open[] = $batch;
            }

            if (count($open) >= $limit) {
                break;
            }
        }

        return $open;
    }

    /**
     * Every round of a revision thread this user still has in temporary
     * storage, whatever its status, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function threadFor(string $userId, string $threadId): array
    {
        if (! self::validId($threadId)) {
            return [];
        }

        $rounds = [];

        foreach ($this->ids() as $id) {
            $batch = $this->find($id);

            if ($batch && ($batch['user'] ?? null) === $userId && self::threadOf($batch) === $threadId) {
                $rounds[] = $batch;
            }
        }

        return array_reverse($rounds);
    }

    /**
     * The revision thread a batch is a round of, if it is one.
     *
     * @param  array<string, mixed>  $batch
     */
    public static function threadOf(array $batch): ?string
    {
        $id = $batch['thread']['id'] ?? null;

        return ($batch['kind'] ?? null) === 'revise' && is_string($id) && self::validId($id) ? $id : null;
    }

    /**
     * Whether the user already has images waiting on Google.
     */
    public function inFlightFor(string $userId): bool
    {
        foreach ($this->ids() as $id) {
            $batch = $this->find($id);

            if ($batch && ($batch['user'] ?? null) === $userId && $this->has($batch, fn (ItemStatus $s) => $s->isGenerating())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove batches older than the retention window. Returns how many went.
     */
    public function prune(?int $hours = null): int
    {
        $hours ??= (int) ($this->config['temp']['retention_hours'] ?? 24);
        $cutoff = now()->getTimestamp() - ($hours * 3600);
        $pruned = 0;

        $batches = [];

        foreach ($this->ids() as $id) {
            $batches[$id] = $this->readJson("{$id}/batch.json");
        }

        // The rounds of a revision thread stay as long as anyone is still
        // working on it, so a long session cannot lose its first rounds
        // before a later one is saved (and their images with it).
        $active = [];

        foreach ($batches as $batch) {
            if ($batch && ($thread = self::threadOf($batch))) {
                $active[$thread] = max($active[$thread] ?? 0, (int) ($batch['created_at'] ?? 0));
            }
        }

        foreach ($batches as $id => $batch) {
            $thread = $batch ? self::threadOf($batch) : null;
            $age = $thread ? $active[$thread] : (int) ($batch['created_at'] ?? 0);

            // A directory with no readable batch.json is debris from an
            // interrupted write, so it goes too.
            if (! $batch || $age < $cutoff) {
                $this->disk()->deleteDirectory($this->path($id));
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Batch ids on disk, newest first. ULIDs sort by creation time.
     *
     * @return array<int, string>
     */
    protected function ids(): array
    {
        $ids = array_filter(
            array_map('basename', $this->disk()->directories($this->root())),
            [self::class, 'validId'],
        );

        rsort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $batch
     * @param  callable(ItemStatus): bool  $test
     */
    protected function has(array $batch, callable $test): bool
    {
        foreach ($batch['items'] as $item) {
            if ($test(ItemStatus::from($item['status']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Background work can die without a word: a killed PHP worker, a server
     * restart. An image that has sat in progress for longer than the work
     * could possibly take is settled here, on read, so it cannot spin forever.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function expire(string $id, array $item): array
    {
        $status = ItemStatus::from($item['status']);
        $idle = now()->getTimestamp() - (int) ($item['updated_at'] ?? 0);

        if ($status->isGenerating() && $idle > (int) ($this->config['timeout'] ?? 180) + 60) {
            return $this->updateItem($id, $item['index'], [
                'status' => ItemStatus::Failed->value,
                'error' => [
                    'code' => 'timed_out',
                    'message' => 'This image took too long and was abandoned. Try again.',
                    'retryable' => true,
                ],
            ]);
        }

        if ($status === ItemStatus::Saving && $idle > self::SAVE_STALE_AFTER) {
            return $this->updateItem($id, $item['index'], [
                'status' => ItemStatus::Complete->value,
                'save_error' => 'Saving took too long and was abandoned. Try again.',
            ]);
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankItem(int $index): array
    {
        return [
            'index' => $index,
            'status' => ItemStatus::Pending->value,
            'attempts' => 0,
            'error' => null,
            'save_error' => null,
            'mime' => null,
            'width' => null,
            'height' => null,
            'bytes' => null,
            'asset' => null,
            'updated_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function writeItem(string $id, array $item): void
    {
        $this->writeJson("{$id}/item-{$item['index']}.json", $item);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(string $relative, array $data): void
    {
        AtomicFile::put(
            $this->disk()->path($this->path($relative)),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readJson(string $relative): ?array
    {
        $contents = $this->disk()->get($this->path($relative));
        $data = $contents ? json_decode($contents, true) : null;

        return is_array($data) ? $data : null;
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->config['temp']['disk'] ?? 'local');
    }

    protected function root(): string
    {
        return trim($this->config['temp']['path'] ?? 'statamic-darkroom/batches', '/');
    }

    protected function path(string $relative): string
    {
        return $this->root().'/'.$relative;
    }
}
