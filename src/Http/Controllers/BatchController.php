<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Http\Controllers\Concerns\FindsBatches;
use D3Creative\Darkroom\Instructions\InstructionStore;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\References\ReferenceStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class BatchController extends CpController
{
    use FindsBatches;

    public function store(
        Request $request,
        ModelRegistry $models,
        Destinations $destinations,
        InstructionStore $instructions,
        BatchStore $store,
        BatchPresenter $presenter,
        ReferenceStore $referenceStore,
    ) {
        $config = config('statamic-darkroom');
        $user = User::current();

        if (blank($config['api_key'] ?? null)) {
            return $this->refuse('missing_api_key', 'No Google API key is configured. Add GEMINI_API_KEY to your .env file.', 422);
        }

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:'.(int) ($config['prompts']['max_length'] ?? 8000)],
            'model' => ['required', 'string', Rule::in($models->ids())],
            'quality' => ['required', 'string'],
            'aspect_ratio' => ['required', 'string'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:'.(int) ($config['batch']['max'] ?? 4)],
            'file_type' => ['required', 'string', Rule::in(array_keys($config['file_types'] ?? []))],
            'container' => ['required', 'string'],
            'folder' => ['nullable', 'string', 'max:255', Destinations::folderRule()],
            'instruction' => ['nullable', 'string'],
            'references' => ['nullable', 'array', 'max:'.(int) ($config['references']['max'] ?? 14)],
            'references.*' => ['string', 'size:26', 'distinct'],
        ], [
            'references.max' => 'Use at most :max reference images.',
        ]);

        // What a model accepts depends on the model, so these are checked
        // against the registry: a mismatch is caught here, for free, rather
        // than by Google after the request has been sent.
        if (! in_array($data['quality'], $models->qualities($data['model']), true)) {
            throw ValidationException::withMessages(['quality' => $models->label($data['model']).' cannot produce '.ModelRegistry::qualityLabel($data['quality']).' images.']);
        }

        if (! in_array($data['aspect_ratio'], $models->aspectRatios($data['model']), true)) {
            throw ValidationException::withMessages(['aspect_ratio' => $models->label($data['model']).' does not support the '.$data['aspect_ratio'].' aspect ratio.']);
        }

        if (! $destinations->find($user, $data['container'])) {
            throw ValidationException::withMessages(['container' => 'Choose an asset container you are allowed to upload to.']);
        }

        $instruction = null;

        if (filled($data['instruction'] ?? null) && ! ($instruction = $instructions->find($data['instruction']))) {
            throw ValidationException::withMessages(['instruction' => 'That system instruction no longer exists.']);
        }

        [$references, $images] = $this->references($data['references'] ?? [], (string) $user->id(), $referenceStore, $config);

        // One batch at a time per person. It keeps a double click, or a second
        // tab, from quietly doubling the bill.
        if ($store->inFlightFor((string) $user->id())) {
            return $this->refuse('in_flight', 'Images are already being generated. Wait for them to finish.');
        }

        $batch = $store->create([
            'user' => (string) $user->id(),
            'prompt' => $data['prompt'],
            'model' => $data['model'],
            'quality' => $data['quality'],
            'aspect_ratio' => $data['aspect_ratio'],
            'file_type' => $data['file_type'],
            'container' => $data['container'],
            'folder' => Destinations::safeFolder($data['folder'] ?? ''),
            'instruction_id' => $instruction['id'] ?? null,
            'instruction_title' => $instruction['title'] ?? null,
            // A copy of the text as it was sent, so the record stays true even
            // if the saved instruction is edited or deleted afterwards.
            'instruction_text' => $instruction['body'] ?? null,
            'references' => $references,
        ], (int) ($data['batch_size'] ?? 1));

        $store->putReferences($batch['id'], $images);

        GenerateBatch::dispatchAfterResponse($batch['id']);

        return response()->json($presenter->present($batch), 201);
    }

    /**
     * The reference images to send, in order: what the batch records about
     * each, and the images themselves, read now so that a prune between here
     * and the job cannot take them away.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, mixed>  $config
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function references(array $ids, string $user, ReferenceStore $store, array $config): array
    {
        $records = [];
        $images = [];

        foreach ($ids as $id) {
            $reference = $store->ownedBy($id, $user);
            $image = $reference ? $store->image($id) : null;

            // Unknown, someone else's and expired all read the same, so the
            // answer says nothing about whose images exist.
            if ($image === null) {
                throw ValidationException::withMessages(['references' => 'A reference image has expired. Add it again.']);
            }

            $records[] = array_filter([
                'type' => $reference['type'],
                'name' => $reference['name'],
                'asset' => $reference['asset'] ?? null,
            ], fn ($value) => $value !== null);

            $images[] = $image;
        }

        // Google refuses a request over 20 MB, and images go as base64,
        // a third larger than the files.
        $encoded = array_sum(array_map('strlen', $images)) * 4 / 3;

        if ($encoded > (float) ($config['references']['max_request_mb'] ?? 18) * 1024 * 1024) {
            throw ValidationException::withMessages(['references' => 'These reference images are too large together. Remove some.']);
        }

        return [$records, $images];
    }

    public function show(string $id, BatchStore $store, BatchPresenter $presenter)
    {
        return response()->json($presenter->present($this->ownedBatch($store, $id)));
    }

    public function destroy(string $id, BatchStore $store)
    {
        $this->ownedBatch($store, $id);

        $store->delete($id);

        return response()->noContent();
    }
}
