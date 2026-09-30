<?php

namespace D3Creative\Darkroom\Support;

use Illuminate\Support\Str;

/**
 * Atomically replace a file: write a temp file beside it, then rename it over
 * the target so a reader never sees a missing or half-written file.
 *
 * Status polling reads these files while a background job rewrites them, so a
 * plain file_put_contents() would occasionally serve half a document.
 *
 * The temp name is unique per write, so two overlapping writers can't consume
 * or delete each other's temp file.
 */
class AtomicFile
{
    public static function put(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}");
        }

        $tmp = $path.'.'.Str::random(12).'.tmp';

        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Could not write {$tmp}");
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException("Could not replace {$path}");
        }
    }
}
