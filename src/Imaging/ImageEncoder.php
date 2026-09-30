<?php

namespace D3Creative\Darkroom\Imaging;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

/**
 * Previews and file type conversion, on the same image driver Statamic's
 * Glide setup uses.
 *
 * Statamic 6 allows Intervention Image 3 or 4, which renamed the read and
 * encode methods between them, so both spellings are handled here.
 */
class ImageEncoder
{
    protected const MIME = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(protected ?string $driver = null) {}

    public static function mime(string $fileType): string
    {
        return self::MIME[$fileType] ?? 'application/octet-stream';
    }

    /**
     * Pixel size read from the header, without decoding the image.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function dimensions(string $binary): array
    {
        $info = @getimagesizefromstring($binary);

        return $info ? [$info[0], $info[1]] : [null, null];
    }

    /**
     * A JPEG no larger than $maxEdge on its longest side, for showing in the
     * page. A 4K original is around 10 MB, far too heavy for a grid of cards.
     */
    public function preview(string $binary, int $maxEdge = 1600, int $quality = 82): string
    {
        $image = $this->read($binary);

        if (max($image->width(), $image->height()) > $maxEdge) {
            $image->scaleDown(width: $maxEdge, height: $maxEdge);
        }

        return $this->encode($image, 'jpg', $quality);
    }

    /**
     * The image as the requested file type. When it is already that type the
     * bytes come back untouched: Google only returns JPEG, and re-compressing
     * a JPEG to make a JPEG would only lose quality.
     */
    public function convert(string $binary, string $sourceMime, string $fileType, int $quality = 90): string
    {
        if (self::mime($fileType) === $sourceMime) {
            return $binary;
        }

        return $this->encode($this->read($binary), $fileType, $quality);
    }

    protected function manager(): ImageManager
    {
        $driver = $this->driver ?? config('statamic.assets.image_manipulation.driver', 'gd');

        return new ImageManager($driver === 'imagick' ? ImagickDriver::class : GdDriver::class);
    }

    protected function read(string $binary)
    {
        $manager = $this->manager();

        return method_exists($manager, 'decodeBinary')
            ? $manager->decodeBinary($binary)
            : $manager->read($binary);
    }

    protected function encode($image, string $fileType, int $quality): string
    {
        // PNG is lossless and rejects a quality option.
        $options = $fileType === 'png' ? [] : ['quality' => $quality];

        $encoded = method_exists($image, 'encodeUsingFileExtension')
            ? $image->encodeUsingFileExtension($fileType, ...$options)
            : $image->encodeByExtension($fileType, ...$options);

        return (string) $encoded;
    }
}
