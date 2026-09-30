<?php

namespace D3Creative\Darkroom\Exceptions;

use RuntimeException;

/**
 * Base for everything that can go wrong while generating or saving an image.
 * Each failure carries a stable code for the UI and a message that is safe to
 * show to the person who pressed Generate.
 */
abstract class DarkroomException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    abstract public function errorCode(): string;

    public function retryable(): bool
    {
        return false;
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{code: string, message: string, retryable: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode(),
            'message' => $this->userMessage(),
            'retryable' => $this->retryable(),
        ];
    }
}
