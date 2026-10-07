<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Http\Controllers\Concerns\FindsSourceImages;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a larger version of an image that already exists: either one that has
 * just been generated and not saved yet, or an asset Darkroom saved earlier.
 *
 * The result is a new batch of one, previewed and saved like any other, so an
 * upscale never replaces the image it came from.
 */
class UpscaleController extends CpController
{
    use FindsSourceImages;

    public function store(
        Request $request,
        ModelRegistry $models,
        Destinations $destinations,
        BatchStore $store,
        BatchPresenter $presenter,
    ) {
        $config = config('statamic-darkroom');
        $user = User::current();

        if (blank($config['api_key'] ?? null)) {
            return $this->refuse('missing_api_key', 'No Google API key is configured. Add GEMINI_API_KEY to your .env file.', 422);
        }

        $data = $request->validate([
            'model' => ['required', 'string', Rule::in($models->ids())],
            'quality' => ['required', 'string'],
            'batch' => ['nullable', 'string', 'required_without:asset'],
            'index' => ['nullable', 'integer', 'required_with:batch'],
            'asset' => ['nullable', 'string', 'required_without:batch'],
        ]);

        if (! in_array($data['quality'], $models->qualities($data['model']), true)) {
            throw ValidationException::withMessages(['quality' => $models->label($data['model']).' cannot produce '.ModelRegistry::qualityLabel($data['quality']).' images.']);
        }

        $source = filled($data['batch'] ?? null)
            ? $this->fromItem($store, $data['batch'], (int) $data['index'], 'This image is not available to upscale.')
            : $this->fromAsset($data['asset'], $config);

        if ($source instanceof Response) {
            return $source;
        }

        // Asking for the same size or smaller would spend money to gain nothing.
        $from = ModelRegistry::rank($source['quality']);

        if ($from !== null && ModelRegistry::rank($data['quality']) <= $from) {
            throw ValidationException::withMessages(['quality' => 'Choose a quality higher than the image already is.']);
        }

        if (! $destinations->find($user, $source['container'])) {
            throw ValidationException::withMessages(['container' => 'You are not allowed to upload to the container this image belongs to.']);
        }

        if ($store->inFlightFor((string) $user->id())) {
            return $this->refuse('in_flight', 'Images are already being generated. Wait for them to finish.');
        }

        $batch = $store->create(array_filter([
            'kind' => 'upscale',
            'user' => (string) $user->id(),
            'prompt' => $source['prompt'],
            'model' => $data['model'],
            'quality' => $data['quality'],
            // Keeping the source's ratio pins the shape. With none, the model
            // takes its shape from the image it is given.
            'aspect_ratio' => in_array($source['aspect_ratio'], $models->aspectRatios($data['model']), true)
                ? $source['aspect_ratio']
                : ModelRegistry::AUTO,
            'file_type' => $source['file_type'],
            'container' => $source['container'],
            'folder' => $source['folder'],
            'upscaled_from' => $source['quality'],
            'source_mime' => $source['mime'],
            // What the original was made from, kept so History and "Reuse
            // prompt" still name it. Only the source is sent.
            'references' => $source['references'] ?? null,
        ], fn ($value) => $value !== null), 1);

        $store->putSource($batch['id'], $source['binary']);

        GenerateBatch::dispatchAfterResponse($batch['id']);

        return response()->json($presenter->present($batch), 201);
    }
}
