<?php

namespace D3Creative\Darkroom\Exceptions;

/**
 * A successful response that contains no usable image and no stated reason.
 * The model sometimes answers with text alone; asking again usually works.
 */
class NoImageReturned extends DarkroomException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct(trim('Google returned no image. '.$detail));
    }

    public function errorCode(): string
    {
        return 'no_image';
    }

    public function userMessage(): string
    {
        return 'Google returned no image for this prompt. Try again, or reword it.';
    }
}
