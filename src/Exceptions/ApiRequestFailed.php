<?php

namespace D3Creative\Darkroom\Exceptions;

/**
 * Google answered with an HTTP error, or could not be reached at all
 * (status 0).
 */
class ApiRequestFailed extends DarkroomException
{
    // Overload, rate limit and gateway errors. Anything else (a bad request,
    // a rejected key, exhausted credit) will fail the same way on a retry.
    protected const RETRYABLE = [0, 408, 429, 500, 502, 503, 504];

    public function __construct(
        public readonly int $status,
        public readonly string $apiCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->apiCode;
    }

    public function retryable(): bool
    {
        return in_array($this->status, self::RETRYABLE, true);
    }

    public function userMessage(): string
    {
        return match (true) {
            $this->status === 0 => 'Could not reach Google. Check the connection and try again.',
            in_array($this->status, [401, 403], true) => 'Google rejected the API key. Check GEMINI_API_KEY and that the key can use this model.',
            $this->status === 402 => 'The Google account has run out of credit.',
            $this->status === 404 => 'Google does not recognise this model. It may have been retired.',
            $this->status === 429 => "Google's rate or spend limit was reached. Wait a minute and try again.",
            $this->status >= 500 => "Google's image service is overloaded right now. Try again shortly.",
            default => 'Google rejected the request: '.$this->getMessage(),
        };
    }
}
