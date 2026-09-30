<?php

namespace D3Creative\Darkroom\Jobs;

use D3Creative\Darkroom\Api\ImageGenerator;
use D3Creative\Darkroom\Api\ImageResult;
use D3Creative\Darkroom\Exceptions\DarkroomException;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Support\Runtime;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Asks Google for every pending image in a batch and records how each one
 * ends. Dispatched with dispatchAfterResponse(), so it runs in the same PHP
 * process once the browser already has its answer: no queue worker needed,
 * and no web server timeout to race.
 *
 * It never throws. Whatever goes wrong is written to the image's status for
 * the page to show, because nobody is listening for an exception by then.
 */
class GenerateBatch
{
    use Dispatchable;

    /**
     * @param  array<int, int>|null  $only  Limit the run to these images, for a retry.
     */
    public function __construct(public string $batchId, public ?array $only = null) {}

    public function handle(ImageGenerator $generator, BatchStore $store, ImageEncoder $encoder, ModelRegistry $models, UsageLog $usage): void
    {
        if (! ($batch = $store->find($this->batchId))) {
            return;
        }

        $config = config('statamic-darkroom');

        Runtime::extend($config, (int) ($config['retry']['deadline'] ?? 300) + 120);

        // An upscale sends the source image with a fixed prompt in place of the
        // user's. The batch still carries the original prompt, so History and
        // "Reuse prompt" show what the image is of, not how it was enlarged.
        $upscaling = ($batch['kind'] ?? null) === 'upscale';
        $prompt = $batch['prompt'];
        $instruction = $batch['instruction_text'] ?? null;
        $references = [];

        if ($upscaling) {
            $prompt = (string) ($config['upscale']['prompt'] ?? 'Reproduce this exact image at a higher resolution.');
            $instruction = null;

            if ($source = $store->source($this->batchId)) {
                $references[] = ['mime_type' => $batch['source_mime'] ?? 'image/jpeg', 'data' => $source];
            }
        }

        $requests = [];

        foreach ($batch['items'] as $item) {
            if ($item['status'] !== ItemStatus::Pending->value) {
                continue;
            }

            if ($this->only !== null && ! in_array($item['index'], $this->only, true)) {
                continue;
            }

            if ($upscaling && $references === []) {
                $this->failWith($store, $item['index'], 'source_missing', 'The image to upscale is no longer available.', false);

                continue;
            }

            $requests[$item['index']] = $models->request(
                $batch['model'],
                $prompt,
                $batch['quality'],
                $batch['aspect_ratio'],
                $instruction,
                $references,
            );

            $store->updateItem($this->batchId, $item['index'], ['status' => ItemStatus::Generating->value]);
        }

        if ($requests === []) {
            return;
        }

        $settled = [];

        try {
            $generator->generateMany($requests, function (string $event, $index, $payload) use ($store, $encoder, $models, $usage, $config, $batch, $upscaling, &$settled) {
                if ($event === 'attempt') {
                    $store->updateItem($this->batchId, $index, ['attempts' => $payload]);
                }

                if ($event === 'settled') {
                    $settled[$index] = true;

                    if (! $payload instanceof ImageResult) {
                        $this->fail($store, $index, $payload);

                        return;
                    }

                    // Logged as soon as Google has returned an image, before
                    // anything is done with it: that is the moment it is
                    // charged for, whatever happens to it afterwards.
                    $usage->record([
                        'source' => 'cp',
                        'kind' => $batch['kind'] ?? 'generate',
                        'user' => $batch['user'] ?? null,
                        'batch' => $this->batchId,
                        'item' => $index,
                        'model' => $batch['model'],
                        'model_label' => $models->label($batch['model']),
                        'quality' => $batch['quality'],
                        'aspect_ratio' => $batch['aspect_ratio'],
                        'price' => $models->price($batch['model'], $batch['quality']),
                        'prompt' => ($upscaling ? 'Upscale: ' : '').$batch['prompt'],
                    ]);

                    $this->complete($store, $encoder, $config, $index, $payload);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        } finally {
            // Anything the generator never reported on must not be left
            // showing as in progress.
            foreach (array_keys($requests) as $index) {
                if (! isset($settled[$index])) {
                    $this->failWith($store, $index, 'internal_error', 'Something went wrong while generating this image.', true);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function complete(BatchStore $store, ImageEncoder $encoder, array $config, int $index, ImageResult $result): void
    {
        try {
            $store->putOriginal($this->batchId, $index, $result->binary);
            $store->putPreview($this->batchId, $index, $this->preview($encoder, $config, $result));

            [$width, $height] = $encoder->dimensions($result->binary);

            $store->updateItem($this->batchId, $index, [
                'status' => ItemStatus::Complete->value,
                'error' => null,
                'mime' => $result->mimeType,
                'width' => $width,
                'height' => $height,
                'bytes' => strlen($result->binary),
            ]);
        } catch (\Throwable $e) {
            report($e);

            $this->failWith($store, $index, 'internal_error', 'The image was generated but could not be stored.', true);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function preview(ImageEncoder $encoder, array $config, ImageResult $result): string
    {
        try {
            return $encoder->preview(
                $result->binary,
                (int) ($config['preview']['max_edge'] ?? 1600),
                (int) ($config['preview']['quality'] ?? 82),
            );
        } catch (\Throwable $e) {
            // A preview is a convenience. If it cannot be made, show the
            // original rather than lose an image that has been paid for.
            report($e);

            return $result->binary;
        }
    }

    protected function fail(BatchStore $store, int $index, DarkroomException $e): void
    {
        Log::warning('Darkroom: image generation failed', [
            'batch' => $this->batchId,
            'item' => $index,
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'context' => $e->context(),
        ]);

        $store->updateItem($this->batchId, $index, [
            'status' => ItemStatus::Failed->value,
            'error' => $e->toArray(),
        ]);
    }

    protected function failWith(BatchStore $store, int $index, string $code, string $message, bool $retryable): void
    {
        $store->updateItem($this->batchId, $index, [
            'status' => ItemStatus::Failed->value,
            'error' => ['code' => $code, 'message' => $message, 'retryable' => $retryable],
        ]);
    }
}
