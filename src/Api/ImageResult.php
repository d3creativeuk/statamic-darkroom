<?php

namespace D3Creative\Darkroom\Api;

final readonly class ImageResult
{
    /**
     * @param  string  $binary  The decoded image bytes, exactly as Google sent them.
     * @param  array<string, mixed>  $meta
     * @param  ?string  $interaction  The stored conversation turn this came from, if any.
     */
    public function __construct(
        public string $binary,
        public string $mimeType,
        public string $model,
        public array $meta = [],
        public ?string $interaction = null,
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
