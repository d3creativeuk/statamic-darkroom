<?php

namespace D3Creative\Darkroom\Jobs;

use D3Creative\Darkroom\Api\ImageGenerator;
use D3Creative\Darkroom\Api\ImageResult;
use D3Creative\Darkroom\Exceptions\DarkroomException;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Revisions\Memory;
use D3Creative\Darkroom\Revisions\RevisionPrompt;
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

        // An upscale or a revision sends the source image with its own prompt
        // in place of the user's: a fixed one to enlarge it, or the notes to
        // change it. The batch still carries the original prompt, so History
        // and "Reuse prompt" show what the image is of. Neither sends the
        // system instruction: the image already carries the style.
        $kind = $batch['kind'] ?? null;
        $upscaling = $kind === 'upscale';
        $revising = $kind === 'revise';
        $fromSource = $upscaling || $revising;
        $instruction = $fromSource ? null : ($batch['instruction_text'] ?? null);
        $references = [];

        if ($fromSource && ($source = $store->source($this->batchId))) {
            $references[] = ['mime_type' => $batch['source_mime'] ?? 'image/jpeg', 'data' => $source];
        }

        // A revision round can carry on the conversation of the round it
        // starts from, so the model sees the earlier rounds (see Memory). Then
        // only the notes are sent: the image is already on Google's side.
        // Decided here rather than when the round was made, so a retry
        // follows the settings as they are now.
        $remember = $revising && Memory::enabled($config);
        $continues = $revising ? Memory::usable($batch['memory'] ?? null, $config) : null;

        $requestFor = fn (bool $continuing) => $models->request(
            $batch['model'],
            match (true) {
                $revising => RevisionPrompt::build((array) ($batch['revision'] ?? []), $config, $continuing),
                $upscaling => (string) ($config['upscale']['prompt'] ?? 'Reproduce this exact image at a higher resolution.'),
                default => $batch['prompt'],
            },
            $batch['quality'],
            $batch['aspect_ratio'],
            $instruction,
            $continuing ? [] : $references,
            $continuing ? $continues : null,
            $remember,
        );

        $missing = fn (int $index) => $this->failWith($store, $index, 'source_missing', $revising ? 'The image to revise is no longer available.' : 'The image to upscale is no longer available.', false);

        $requests = [];

        foreach ($batch['items'] as $item) {
            if ($item['status'] !== ItemStatus::Pending->value) {
                continue;
            }

            if ($this->only !== null && ! in_array($item['index'], $this->only, true)) {
                continue;
            }

            if ($fromSource && $continues === null && $references === []) {
                $missing($item['index']);

                continue;
            }

            $requests[$item['index']] = $requestFor($continues !== null);

            $store->updateItem($this->batchId, $item['index'], ['status' => ItemStatus::Generating->value]);
        }

        if ($requests === []) {
            return;
        }

        $settled = [];
        // Rounds whose conversation had gone, to send again from the image.
        $lost = [];
        // "continued" while carrying a conversation on, "lost" when sending
        // again because it had gone, otherwise null.
        $memory = $continues !== null ? 'continued' : null;

        $onEvent = function (string $event, $index, $payload) use ($store, $encoder, $models, $usage, $config, $batch, $upscaling, $revising, &$settled, &$lost, &$memory) {
            if ($event === 'attempt') {
                $store->updateItem($this->batchId, $index, ['attempts' => $payload]);
            }

            if ($event !== 'settled') {
                return;
            }

            $settled[$index] = true;

            if (! $payload instanceof ImageResult) {
                if ($memory === 'continued' && Memory::lost($payload)) {
                    $lost[$index] = true;
                    unset($settled[$index]);

                    return;
                }

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
                'prompt' => ($upscaling ? 'Upscale: ' : ($revising ? 'Revise: ' : '')).$batch['prompt'],
            ]);

            $this->complete($store, $encoder, $config, $index, $payload, $memory);
        };

        try {
            $generator->generateMany($requests, $onEvent);

            // A conversation can have gone (Google keeps them for a limited
            // time). Those rounds go again as fresh ones, from the image.
            if ($lost !== []) {
                Log::info('Darkroom: a revision conversation had expired, so the round was sent again from the image.', ['batch' => $this->batchId]);

                $memory = 'lost';
                $again = [];

                foreach (array_keys($lost) as $index) {
                    if ($references === []) {
                        $settled[$index] = true;
                        $missing($index);
                    } else {
                        $again[$index] = $requestFor(false);
                    }
                }

                if ($again !== []) {
                    $generator->generateMany($again, $onEvent);
                }
            }
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
    protected function complete(BatchStore $store, ImageEncoder $encoder, array $config, int $index, ImageResult $result, ?string $memory = null): void
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
                // The stored turn a later revision round can carry on.
                'interaction' => $result->interaction,
                'memory' => $memory,
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
