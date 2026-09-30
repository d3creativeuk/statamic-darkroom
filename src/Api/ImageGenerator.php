<?php

namespace D3Creative\Darkroom\Api;

use D3Creative\Darkroom\Exceptions\DarkroomException;

interface ImageGenerator
{
    /**
     * @throws DarkroomException
     */
    public function generate(ImageRequest $request): ImageResult;

    /**
     * Generate several images at once. Never throws for an individual image:
     * each key comes back as either its result or the exception it ended on.
     *
     * $progress is called as each thing happens, so a caller can record it
     * without waiting for the whole batch:
     *   $progress('attempt', $key, int $attempt)
     *   $progress('settled', $key, ImageResult|DarkroomException $outcome)
     *
     * @param  array<array-key, ImageRequest>  $requests
     * @param  (callable(string, array-key, mixed): void)|null  $progress
     * @return array<array-key, ImageResult|DarkroomException>
     */
    public function generateMany(array $requests, ?callable $progress = null): array;
}
