<?php

namespace D3Creative\Darkroom\Exceptions;

/**
 * Google accepted the request but refused to produce an image, usually on
 * safety or policy grounds. Comes back as HTTP 200 with a reason and no image.
 */
class GenerationBlocked extends DarkroomException
{
    public function __construct(public readonly string $reason, ?string $detail = null)
    {
        parent::__construct(trim('Google blocked this image ('.$reason.'). '.$detail));
    }

    public function errorCode(): string
    {
        return 'blocked';
    }

    public function userMessage(): string
    {
        return 'Google blocked this image ('.strtolower(str_replace('_', ' ', $this->reason)).'). Try rewording the prompt.';
    }
}
