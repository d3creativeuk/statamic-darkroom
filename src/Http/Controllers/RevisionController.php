<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Http\Controllers\Concerns\FindsSourceImages;
use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\Jobs\GenerateBatch;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Revisions\RevisionPrompt;
use D3Creative\Darkroom\Revisions\Threads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changes an image that already exists, from notes pinned to spots on it and
 * an optional note for the whole image.
 *
 * Like an upscale, the result is a new batch of one, previewed and saved like
 * any other, so a revision never replaces the image it came from.
 */
class RevisionController extends CpController
{
    use FindsSourceImages;

    public function store(
        Request $request,
        ModelRegistry $models,
        Destinations $destinations,
        BatchStore $store,
        BatchPresenter $presenter,
        Threads $threads,
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
            'notes' => ['nullable', 'array', 'max:'.RevisionPrompt::MAX_NOTES],
            // Where each note was pinned, as a share of the width and height.
            'notes.*.x' => ['required', 'numeric', 'between:0,1'],
            'notes.*.y' => ['required', 'numeric', 'between:0,1'],
            'notes.*.text' => ['required', 'string', 'max:500'],
            'general' => ['nullable', 'string', 'max:2000'],
        ]);

        $notes = collect($data['notes'] ?? [])
            ->map(fn ($note) => ['x' => round((float) $note['x'], 4), 'y' => round((float) $note['y'], 4), 'text' => trim($note['text'])])
            ->filter(fn ($note) => $note['text'] !== '')
            ->values()
            ->all();
        $general = trim((string) ($data['general'] ?? ''));

        if ($notes === [] && $general === '') {
            throw ValidationException::withMessages(['notes' => 'Add at least one note saying what to change.']);
        }

        if (! in_array($data['quality'], $models->qualities($data['model']), true)) {
            throw ValidationException::withMessages(['quality' => $models->label($data['model']).' cannot produce '.ModelRegistry::qualityLabel($data['quality']).' images.']);
        }

        $source = filled($data['batch'] ?? null)
            ? $this->fromItem($store, $data['batch'], (int) $data['index'], 'This image is not available to revise.')
            : $this->fromAsset($data['asset'], $config);

        if ($source instanceof Response) {
            return $source;
        }

        if (! $destinations->find($user, $source['container'])) {
            throw ValidationException::withMessages(['container' => 'You are not allowed to upload to the container this image belongs to.']);
        }

        if ($store->inFlightFor((string) $user->id())) {
            return $this->refuse('in_flight', 'Images are already being generated. Wait for them to finish.');
        }

        $batch = $store->create(array_filter([
            'kind' => 'revise',
            'user' => (string) $user->id(),
            'prompt' => $source['prompt'],
            'model' => $data['model'],
            'quality' => $data['quality'],
            'aspect_ratio' => in_array($source['aspect_ratio'], $models->aspectRatios($data['model']), true)
                ? $source['aspect_ratio']
                : ModelRegistry::AUTO,
            'file_type' => $source['file_type'],
            'container' => $source['container'],
            'folder' => $source['folder'],
            // Kept so History and "Reuse prompt" still name the style it was made in.
            'instruction_id' => $source['instruction_id'] ?? null,
            'instruction_title' => $source['instruction_title'] ?? null,
            'revision' => ['notes' => $notes, 'general' => $general !== '' ? $general : null],
            // The rounds this one follows on from, so the feed can show them.
            'thread' => $threads->next($source),
            'source_mime' => $source['mime'],
        ], fn ($value) => $value !== null), 1);

        $store->putSource($batch['id'], $source['binary']);

        GenerateBatch::dispatchAfterResponse($batch['id']);

        return response()->json($presenter->present($batch), 201);
    }

    /**
     * Every round of a thread, for the Revise panel's feed. Opened from a
     * saved image, the rounds stamped on it are included.
     */
    public function thread(Request $request, string $thread, Threads $threads)
    {
        $asset = null;

        if ($request->filled('asset')) {
            $asset = Asset::find((string) $request->query('asset'));

            abort_unless($asset, 404);
            abort_unless(Gate::forUser(User::current())->allows('view', $asset), 403);
        }

        $feed = $threads->feed(User::current(), $thread, $asset);

        abort_unless($feed, 404);

        return response()->json($feed);
    }

    /**
     * A large preview of a saved image, to pin notes on. Core only makes small
     * thumbnails, and a container may have no public URL at all.
     */
    public function preview(Request $request, ImageEncoder $encoder)
    {
        $asset = Asset::find((string) $request->query('asset'));

        abort_unless($asset && $asset->isImage(), 404);
        abort_unless(Gate::forUser(User::current())->allows('view', $asset), 403);

        $config = config('statamic-darkroom.preview', []);

        return response($encoder->preview($asset->contents(), (int) ($config['max_edge'] ?? 1600), (int) ($config['quality'] ?? 82)), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
