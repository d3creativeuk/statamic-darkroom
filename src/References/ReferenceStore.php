<?php

namespace D3Creative\Darkroom\References;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Support\AtomicFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * Reference images waiting to be sent with a prompt, kept outside the web
 * root and outside every asset container, so they never become assets and
 * Glide never sees them. One directory per image:
 *
 *   image.jpg        the image as it will be sent: a JPEG, already reduced
 *   reference.json   who added it and what it was; written last, so a
 *                    directory without it is either still being written
 *                    or debris, and is left until it is past the cutoff
 *
 * A batch copies the images it uses when it is created, so pruning one here
 * cannot strand a generation that is still running.
 */
class ReferenceStore
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    /**
     * A name safe to store and to show: the name alone, never a path the
     * browser sent, valid UTF-8, and without the characters that could start
     * markup, the same ones Statamic replaces in uploaded filenames. The
     * Control Panel shows some messages as HTML.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', mb_scrub($name, 'UTF-8')));

        return Str::limit(str_replace(['<', '>', '"', "'"], '-', $name), 120, '') ?: 'image';
    }

    /**
     * @param  array<string, mixed>  $record  type, name and, for a library image, asset.
     * @return array<string, mixed>
     */
    public function create(string $jpeg, array $record, ?string $user, int $width, int $height): array
    {
        $id = strtolower((string) Str::ulid());

        $this->disk()->put($this->path("{$id}/image.jpg"), $jpeg);

        AtomicFile::put($this->disk()->path($this->path("{$id}/reference.json")), json_encode(array_merge($record, [
            'id' => $id,
            'user' => $user,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($jpeg),
            'created_at' => now()->getTimestamp(),
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return $this->find($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! BatchStore::validId($id)) {
            return null;
        }

        $contents = $this->disk()->get($this->path("{$id}/reference.json"));
        $record = $contents ? json_decode($contents, true) : null;

        return is_array($record) ? $record : null;
    }

    /**
     * The reference, if it belongs to this user and has not been pruned.
     *
     * @return array<string, mixed>|null
     */
    public function ownedBy(string $id, ?string $user): ?array
    {
        $record = $this->find($id);

        return $record && $user !== null && ($record['user'] ?? null) === $user ? $record : null;
    }

    public function image(string $id): ?string
    {
        return BatchStore::validId($id) ? $this->disk()->get($this->path("{$id}/image.jpg")) : null;
    }

    /**
     * Remove references older than the retention window, and directories an
     * interrupted write left without their record once they are as old.
     * Returns how many went.
     */
    public function prune(?int $hours = null): int
    {
        $hours ??= (int) ($this->config['temp']['retention_hours'] ?? 24);
        $cutoff = now()->getTimestamp() - ($hours * 3600);
        $pruned = 0;

        foreach ($this->disk()->directories($this->root()) as $directory) {
            $id = basename($directory);

            if (! BatchStore::validId($id)) {
                continue;
            }

            $record = $this->find($id);

            // A directory without its record may be one create() is writing
            // at this moment (the image goes first), so it is aged by the time
            // in its ULID, never removed on sight.
            $created = $record['created_at']
                ?? (Str::isUlid($id) ? Ulid::fromString($id)->getDateTime()->getTimestamp() : 0);

            if ((int) $created < $cutoff) {
                $this->disk()->deleteDirectory($this->path($id));
                $pruned++;
            }
        }

        return $pruned;
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->config['temp']['disk'] ?? 'local');
    }

    protected function root(): string
    {
        return trim($this->config['references']['path'] ?? 'statamic-darkroom/references', '/');
    }

    protected function path(string $relative): string
    {
        return $this->root().'/'.$relative;
    }
}
