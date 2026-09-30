<?php

namespace D3Creative\Darkroom\Exceptions;

class NoAltText extends DarkroomException
{
    public function __construct()
    {
        parent::__construct('Google returned no alt text.');
    }

    public function errorCode(): string
    {
        return 'no_alt_text';
    }

    public function userMessage(): string
    {
        return 'Google did not suggest any alt text. Try again, or write it yourself.';
    }
}
