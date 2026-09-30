<?php

namespace D3Creative\Darkroom\Exceptions;

class MissingApiKey extends DarkroomException
{
    public function __construct()
    {
        parent::__construct('No Google API key is configured. Add GEMINI_API_KEY to your .env file.');
    }

    public function errorCode(): string
    {
        return 'missing_api_key';
    }
}
