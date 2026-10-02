<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Api\AltTextWriter;
use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Exceptions\DarkroomException;
use D3Creative\Darkroom\Exceptions\MissingApiKey;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\Http\Controllers\Concerns\FindsBatches;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Jobs\SaveItem;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class ItemController extends CpController
{
    use FindsBatches;

    public function preview(string $id, int $index, BatchStore $store)
    {
        $this->ownedItem($this->ownedBatch($store, $id), $index);

        abort_unless($store->hasPreview($id, $index), 404);

        // Unsaved images are private to whoever generated them, so nothing
        // along the way should keep a copy.
        return $store->disk()->response($store->previewPath($id, $index), null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function save(
        Request $request,
        string $id,
        int $index,
        BatchStore $store,
        BatchPresenter $presenter,
        Destinations $destinations,
    ) {
        $batch = $this->ownedBatch($store, $id);
        $item = $this->ownedItem($batch, $index);
        $config = config('statamic-darkroom');

        $data = $request->validate([
            'filename' => ['nullable', 'string', 'max:120'],
            // Where to save is chosen at save time. The batch's own folder is
            // only the suggestion the picker opened on.
            'container' => ['nullable', 'string'],
            'folder' => ['nullable', 'string', 'max:255', Destinations::folderRule()],
            'file_type' => ['nullable', 'string', Rule::in(array_keys($config['file_types'] ?? []))],
            'alt' => ['nullable', 'string', 'max:500'],
        ]);

        if ($item['status'] !== ItemStatus::Complete->value) {
            return $this->refuse('not_ready', 'This image is not ready to be saved.');
        }

        $container = $data['container'] ?? $batch['container'];

        // Checked here even for the batch's own container: permissions may
        // have changed since the image was generated.
        abort_unless($destinations->find(User::current(), $container), 403);

        $store->updateItem($id, $index, ['status' => ItemStatus::Saving->value, 'save_error' => null]);

        SaveItem::dispatchAfterResponse(
            $id,
            $index,
            Destinations::safeFilename($data['filename'] ?? null, $presenter->filename($batch['prompt'], $index, (int) $batch['count'], $presenter->suffix($batch))),
            $data['file_type'] ?? $batch['file_type'],
            $data['alt'] ?? null,
            $container,
            Destinations::safeFolder(array_key_exists('folder', $data) ? $data['folder'] : ($batch['folder'] ?? '')),
        );

        return response()->json($presenter->present($store->find($id)), 202);
    }

    /**
     * Suggest alt text for an image before it is saved. Answered in the
     * request: it takes a few seconds and someone is waiting for it.
     */
    public function alt(string $id, int $index, BatchStore $store, AltTextWriter $writer, UsageLog $usage)
    {
        $batch = $this->ownedBatch($store, $id);
        $item = $this->ownedItem($batch, $index);

        if (! in_array($item['status'], [ItemStatus::Complete->value, ItemStatus::Saving->value], true) || ! $store->hasPreview($id, $index)) {
            return $this->refuse('not_ready', 'This image is not ready yet.');
        }

        // The preview is enough to describe, and a fraction of the upload.
        $preview = $store->disk()->get($store->previewPath($id, $index));

        try {
            $result = $writer->write($preview, 'image/jpeg', $batch['prompt']);
        } catch (DarkroomException $e) {
            return $this->refuse($e->errorCode(), $e->userMessage(), $e instanceof MissingApiKey ? 422 : 502);
        }

        $usage->record([
            'source' => 'cp',
            'kind' => 'alt_text',
            'user' => (string) User::current()->id(),
            'batch' => $id,
            'item' => $index,
            'model' => $writer->model(),
            'model_label' => 'Alt text ('.config('statamic-darkroom.alt_text.label', $writer->model()).')',
            'quality' => null,
            'aspect_ratio' => null,
            'price' => $writer->price($result['input_tokens'], $result['output_tokens']),
            'prompt' => 'Alt text: '.$batch['prompt'],
        ]);

        return response()->json(['alt' => $result['text']]);
    }

    public function retry(string $id, int $index, BatchStore $store, BatchPresenter $presenter)
    {
        $batch = $this->ownedBatch($store, $id);
        $item = $this->ownedItem($batch, $index);

        if ($item['status'] !== ItemStatus::Failed->value) {
            return $this->refuse('not_failed', 'Only a failed image can be tried again.');
        }

        if (blank(config('statamic-darkroom.api_key'))) {
            return $this->refuse('missing_api_key', 'No Google API key is configured. Add GEMINI_API_KEY to your .env file.', 422);
        }

        if ($store->inFlightFor((string) User::current()->id())) {
            return $this->refuse('in_flight', 'Images are already being generated. Wait for them to finish.');
        }

        $store->resetItem($id, $index);

        GenerateBatch::dispatchAfterResponse($id, [$index]);

        return response()->json($presenter->present($store->find($id)), 202);
    }

    public function destroy(string $id, int $index, BatchStore $store, BatchPresenter $presenter)
    {
        $item = $this->ownedItem($this->ownedBatch($store, $id), $index);

        if (ItemStatus::from($item['status'])->isActive()) {
            return $this->refuse('busy', 'This image is still being worked on.');
        }

        // A saved image is already in the asset library. Discarding only
        // removes the temporary copy, never the asset.
        if ($item['status'] !== ItemStatus::Saved->value) {
            $store->updateItem($id, $index, ['status' => ItemStatus::Discarded->value]);
        }

        $store->deleteItemFiles($id, $index);

        return response()->json($presenter->present($store->find($id)));
    }
}
