<?php

namespace D3Creative\Darkroom\Jobs;

use D3Creative\Darkroom\Assets\AssetSaver;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Revisions\Threads;
use D3Creative\Darkroom\Support\Runtime;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Validation\ValidationException;

/**
 * Saves one generated image into the asset library, after the response.
 *
 * Creating the asset is quick, but Statamic then warms every Glide preset for
 * it, and on a sync queue that happens right here. From a full-size source
 * that can take far longer than a web request is allowed.
 */
class SaveItem
{
    use Dispatchable;

    public function __construct(
        public string $batchId,
        public int $index,
        public string $filename,
        public string $fileType,
        public ?string $alt = null,
        public ?string $container = null,
        public ?string $folder = null,
    ) {}

    public function handle(BatchStore $store, AssetSaver $saver, Threads $threads): void
    {
        $batch = $store->find($this->batchId);
        $item = $store->item($this->batchId, $this->index);

        if (! $batch || ! $item || $item['status'] !== ItemStatus::Saving->value) {
            return;
        }

        Runtime::extend(config('statamic-darkroom'), 900);

        try {
            $binary = $store->original($this->batchId, $this->index)
                ?? throw new \RuntimeException('The generated image is no longer available.');

            $asset = $saver->save(
                $binary,
                $item['mime'] ?? 'image/jpeg',
                $this->container ?? $batch['container'],
                $this->folder ?? $batch['folder'] ?? '',
                $this->filename,
                $this->fileType,
                $this->alt,
                // The prompt and settings travel with the asset. That is what
                // the History tab reads, and what lets a prompt be reused.
                [SavedImages::KEY => SavedImages::stamp($batch, $threads->trail($batch, $item))],
            );

            $store->updateItem($this->batchId, $this->index, [
                'status' => ItemStatus::Saved->value,
                'save_error' => null,
                'asset' => [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'url' => $asset->url(),
                    'edit_url' => $asset->editUrl(),
                ],
            ]);

            // From here the image is an asset like any other and shows up in
            // History, so the temporary copies have done their job.
            $store->deleteItemFiles($this->batchId, $this->index);
        } catch (\Throwable $e) {
            report($e);

            // Back to a finished image, so it can be saved again.
            $store->updateItem($this->batchId, $this->index, [
                'status' => ItemStatus::Complete->value,
                'save_error' => $e instanceof ValidationException
                    ? collect($e->errors())->flatten()->first()
                    : 'Could not save this image: '.$e->getMessage(),
            ]);
        }
    }
}
