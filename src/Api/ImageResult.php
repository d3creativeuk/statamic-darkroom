<?php

namespace D3Creative\Darkroom\Api;

final readonly class ImageResult
{
    /**
     * @param  string  $binary  The decoded image bytes, exactly as Google sent them.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $binary,
        public string $mimeType,
        public string $model,
        public array $meta = [],
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
