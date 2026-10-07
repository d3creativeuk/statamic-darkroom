<?php

namespace D3Creative\Darkroom\Api;

use D3Creative\Darkroom\Exceptions\ApiRequestFailed;
use D3Creative\Darkroom\Exceptions\DarkroomException;
use D3Creative\Darkroom\Exceptions\GenerationBlocked;
use D3Creative\Darkroom\Exceptions\MissingApiKey;
use D3Creative\Darkroom\Exceptions\NoImageReturned;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Shared transport for the two Gemini endpoints: auth, concurrency, retries
 * and error mapping. A driver only says where to post, what to post and how
 * to find the image in the answer.
 */
abstract class AbstractGeminiClient implements ImageGenerator
{
    // Reasons Google gives when it refuses on policy grounds. Both APIs use
    // these words, one in SCREAMING_CASE and one in snake_case.
    protected const BLOCKED = '/safety|prohibited|blocked|blocklist|recitation|spii/i';

    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    abstract protected function url(ImageRequest $request): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(ImageRequest $request): array;

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws DarkroomException
     */
    abstract protected function parse(array $json, ImageRequest $request): ImageResult;

    public function generate(ImageRequest $request): ImageResult
    {
        $outcome = $this->generateMany([$request])[0];

        if ($outcome instanceof DarkroomException) {
            throw $outcome;
        }

        return $outcome;
    }

    public function generateMany(array $requests, ?callable $progress = null): array
    {
        $progress ??= static fn () => null;
        $outcomes = [];

        $settle = function ($key, ImageResult|DarkroomException $outcome) use (&$outcomes, $progress) {
            $outcomes[$key] = $outcome;
            $progress('settled', $key, $outcome);
        };

        if (blank($this->config['api_key'] ?? null)) {
            foreach (array_keys($requests) as $key) {
                $settle($key, new MissingApiKey);
            }

            return $outcomes;
        }

        $maxAttempts = max(1, (int) ($this->config['retry']['attempts'] ?? 3));
        $deadline = microtime(true) + (int) ($this->config['retry']['deadline'] ?? 300);
        $pending = $requests;

        for ($attempt = 1; $pending !== []; $attempt++) {
            foreach (array_keys($pending) as $key) {
                $progress('attempt', $key, $attempt);
            }

            $responses = $this->send($pending);
            $retryAfter = 0;

            foreach ($pending as $key => $request) {
                try {
                    $settle($key, $this->interpret($responses[(string) $key] ?? null, $request));
                } catch (DarkroomException $e) {
                    if ($e->retryable() && $attempt < $maxAttempts) {
                        $retryAfter = max($retryAfter, $e instanceof ApiRequestFailed ? (int) $e->retryAfter : 0);

                        continue;
                    }

                    $settle($key, $e);
                }

                unset($pending[$key], $responses[(string) $key]);
            }

            if ($pending === []) {
                break;
            }

            $delay = $this->delay($attempt, $retryAfter);

            // No point waiting if the wait itself would run past the deadline.
            if (microtime(true) + $delay >= $deadline) {
                foreach (array_keys($pending) as $key) {
                    $settle($key, new ApiRequestFailed(504, 'deadline_exceeded', 'Gave up retrying before the deadline.'));
                }

                break;
            }

            Sleep::for($delay)->seconds();
        }

        return $outcomes;
    }

    /**
     * Post every pending request at once.
     *
     * @param  array<array-key, ImageRequest>  $requests
     * @return array<string, Response|\Throwable>
     */
    protected function send(array $requests): array
    {
        $concurrency = max(1, (int) ($this->config['batch']['concurrency'] ?? 4));

        return Http::pool(function (Pool $pool) use ($requests) {
            foreach ($requests as $key => $request) {
                $pool->as((string) $key)
                    ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
                    ->timeout((int) ($this->config['timeout'] ?? 180))
                    ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                    ->acceptJson()
                    ->asJson()
                    ->post($this->url($request), $this->payload($request));
            }
        }, $concurrency);
    }

    /**
     * @throws DarkroomException
     */
    protected function interpret(Response|\Throwable|null $response, ImageRequest $request): ImageResult
    {
        if (! $response instanceof Response) {
            throw new ApiRequestFailed(0, 'connection_failed', $response?->getMessage() ?? 'No response.');
        }

        // Decoded straight from the body rather than through $response->json(),
        // which would keep a second copy of a very large payload alive.
        $json = json_decode($response->body(), true);

        if ($response->failed()) {
            throw $this->requestFailed($response, is_array($json) ? $json : []);
        }

        if (! is_array($json)) {
            throw new NoImageReturned('The response was not valid JSON.');
        }

        return $this->parse($json, $request);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function requestFailed(Response $response, array $json): ApiRequestFailed
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];

        // generateContent: {"code": 400, "status": "INVALID_ARGUMENT"}
        // Interactions:    {"code": "invalid_request"}
        $code = is_string($error['code'] ?? null) ? $error['code'] : ($error['status'] ?? 'http_'.$response->status());

        $retryAfter = $response->header('Retry-After');

        return new ApiRequestFailed(
            $response->status(),
            strtolower((string) $code),
            (string) ($error['message'] ?? 'HTTP '.$response->status()),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Turn "200 but no image" into the right failure: a policy block when the
     * response says so, otherwise a plain missing image.
     *
     * @param  array<int, mixed>  $reasons  Anything in the response that reads like a reason.
     * @param  array<int, string>  $text  Any text the model returned in place of an image.
     * @param  array<string, mixed>  $json
     */
    protected function noImage(array $reasons, array $text, array $json): DarkroomException
    {
        $detail = trim(implode(' ', $text)) ?: null;

        foreach ($reasons as $reason) {
            if (is_string($reason) && preg_match(self::BLOCKED, $reason)) {
                return (new GenerationBlocked($reason, $detail))->withContext(self::redact($json));
            }
        }

        $stated = implode(', ', array_filter($reasons, 'is_string'));

        return (new NoImageReturned(trim(($stated ? "Reason: {$stated}. " : '').$detail)))->withContext(self::redact($json));
    }

    protected function decode(string $base64): string
    {
        $binary = base64_decode($base64, true);

        if ($binary === false || $binary === '') {
            throw new NoImageReturned('The image data could not be decoded.');
        }

        return $binary;
    }

    /**
     * The usage figures worth keeping: the plain numbers, plus the tokens
     * billed for images sent with the prompt, which Google only gives inside
     * a per-modality list. The rest of the usage block runs to nested lists
     * of every model call.
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, scalar>
     */
    protected function usage(array $usage, string $list, string $modalityKey, string $tokensKey): array
    {
        $kept = array_filter($usage, 'is_scalar');

        foreach ((array) ($usage[$list] ?? []) as $entry) {
            if (strtolower((string) ($entry[$modalityKey] ?? '')) === 'image') {
                $kept['input_image_tokens'] = ($kept['input_image_tokens'] ?? 0) + (int) ($entry[$tokensKey] ?? 0);
            }
        }

        return $kept;
    }

    /**
     * Seconds to wait before the next round: the configured backoff, or what
     * Google asked for if that is longer, capped so one slow header cannot
     * stall the batch.
     */
    protected function delay(int $attempt, int $retryAfter): int
    {
        $backoff = array_values((array) ($this->config['retry']['backoff'] ?? [2, 6]));
        $wait = (int) ($backoff[$attempt - 1] ?? end($backoff) ?: 2);
        $cap = (int) ($this->config['retry']['max_retry_after'] ?? 30);

        return max($wait, min($retryAfter, $cap));
    }

    /**
     * A copy of a response that is safe to store: image data and thought
     * signatures run to megabytes, so long strings become placeholders.
     *
     * @param  array<array-key, mixed>  $json
     * @return array<array-key, mixed>
     */
    public static function redact(array $json): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return self::redact($value);
            }

            return is_string($value) && strlen($value) > 500
                ? '<'.strlen($value).' characters>'
                : $value;
        }, $json);
    }
}
