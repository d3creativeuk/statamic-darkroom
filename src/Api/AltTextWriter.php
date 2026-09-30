<?php

namespace D3Creative\Darkroom\Api;

use D3Creative\Darkroom\Exceptions\ApiRequestFailed;
use D3Creative\Darkroom\Exceptions\MissingApiKey;
use D3Creative\Darkroom\Exceptions\NoAltText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Writes alt text by showing an image to one of Google's text models, on the
 * same API key as the image models. A single short answer, so it runs inside
 * the request rather than in the background.
 */
class AltTextWriter
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    /**
     * @param  ?string  $context  The prompt the image was made from. Given to
     *                            the model as background, since describing
     *                            what the picture is for helps it pick out
     *                            what matters.
     * @return array{text: string, input_tokens: int, output_tokens: int}
     */
    public function write(string $binary, string $mime, ?string $context = null): array
    {
        if (blank($this->config['api_key'] ?? null)) {
            throw new MissingApiKey;
        }

        $instruction = (string) ($this->config['alt_text']['prompt'] ?? 'Write alt text for this image. Reply with the alt text only.');

        if (filled($context)) {
            $instruction .= "\n\nFor context only, the image was generated from this prompt: ".$context;
        }

        $response = $this->send([
            'model' => $this->model(),
            'input' => [
                ['type' => 'text', 'text' => $instruction],
                ['type' => 'image', 'mime_type' => $mime, 'data' => base64_encode($binary)],
            ],
            'store' => false,
        ]);

        $json = $response->json() ?? [];
        $text = [];

        foreach ($json['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $block) {
                if (isset($block['text'])) {
                    $text[] = $block['text'];
                }
            }
        }

        // Models like to wrap an answer in quotes or add a line break.
        $alt = trim(implode(' ', $text), " \t\n\r\"'“”‘’");

        if ($alt === '') {
            throw new NoAltText;
        }

        $usage = $json['usage'] ?? [];

        return [
            'text' => Str::limit(preg_replace('/\s+/', ' ', $alt), 500, ''),
            'input_tokens' => (int) ($usage['total_input_tokens'] ?? 0),
            // Thinking is billed at the output rate.
            'output_tokens' => (int) ($usage['total_output_tokens'] ?? 0) + (int) ($usage['total_thought_tokens'] ?? 0),
        ];
    }

    public function model(): string
    {
        return (string) ($this->config['alt_text']['model'] ?? 'gemini-3.5-flash-lite');
    }

    /**
     * What a call cost at the configured rates, in US dollars.
     */
    public function price(int $inputTokens, int $outputTokens): float
    {
        return round(
            $inputTokens * (float) ($this->config['alt_text']['input_price'] ?? 0) / 1_000_000
            + $outputTokens * (float) ($this->config['alt_text']['output_price'] ?? 0) / 1_000_000,
            6,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): Response
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->config['api_key']])
                ->timeout((int) ($this->config['alt_text']['timeout'] ?? 60))
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                ->acceptJson()
                ->asJson()
                // Someone is waiting on this, so one quick second try on an
                // overload and no more.
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                    || in_array($e->response?->status(), [408, 429, 500, 502, 503, 504], true), throw: false)
                ->post(rtrim($this->config['base_url'], '/').'/interactions', $payload);
        } catch (ConnectionException $e) {
            throw new ApiRequestFailed(0, 'connection_failed', $e->getMessage());
        }

        if ($response->failed()) {
            $error = (array) ($response->json('error') ?? []);
            $code = is_string($error['code'] ?? null) ? $error['code'] : ($error['status'] ?? 'http_'.$response->status());

            throw new ApiRequestFailed($response->status(), strtolower((string) $code), (string) ($error['message'] ?? 'HTTP '.$response->status()));
        }

        return $response;
    }
}
