<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Http\Controllers\Concerns\FindsBatches;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
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
    use FindsBatches;

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
            ? $this->fromItem($store, $data['batch'], (int) $data['index'])
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

        $batch = $store->create([
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
        ], 1);

        $store->putSource($batch['id'], $source['binary']);

        GenerateBatch::dispatchAfterResponse($batch['id']);

        return response()->json($presenter->present($batch), 201);
    }

    /**
     * An image that has been generated but not saved yet.
     *
     * @return array<string, mixed>|Response
     */
    protected function fromItem(BatchStore $store, string $batchId, int $index)
    {
        $batch = $this->ownedBatch($store, $batchId);
        $item = $this->ownedItem($batch, $index);

        if ($item['status'] !== ItemStatus::Complete->value || ! ($binary = $store->original($batchId, $index))) {
            return $this->refuse('not_ready', 'This image is not available to upscale.');
        }

        return [
            'binary' => $binary,
            'mime' => $item['mime'] ?? 'image/jpeg',
            'prompt' => $batch['prompt'],
            'quality' => $batch['quality'] ?? null,
            'aspect_ratio' => $batch['aspect_ratio'] ?? ModelRegistry::AUTO,
            'file_type' => $batch['file_type'],
            'container' => $batch['container'],
            'folder' => $batch['folder'] ?? '',
        ];
    }

    /**
     * An asset, normally one Darkroom saved earlier and listed in History.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function fromAsset(string $id, array $config): array
    {
        $asset = Asset::find($id);

        abort_unless($asset && $asset->isImage(), 404);
        abort_unless(Gate::forUser(User::current())->allows('view', $asset), 403);

        $stamp = (array) $asset->get(SavedImages::KEY);
        $extension = strtolower((string) $asset->extension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return [
            'binary' => $asset->contents(),
            'mime' => $asset->mimeType(),
            'prompt' => $stamp['prompt'] ?? $asset->basename(),
            'quality' => $stamp['quality'] ?? null,
            'aspect_ratio' => $stamp['aspect_ratio'] ?? ModelRegistry::AUTO,
            // Saved in the same type as the original where that is one on offer.
            'file_type' => array_key_exists($extension, $config['file_types'] ?? [])
                ? $extension
                : ($config['defaults']['file_type'] ?? 'jpg'),
            'container' => $asset->containerHandle(),
            'folder' => trim((string) $asset->folder(), '/.'),
        ];
    }
}
