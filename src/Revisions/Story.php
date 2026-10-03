<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Trash;
use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;

/**
 * How a saved revised image was made, for History: the prompt, the original,
 * then each round's notes and the image that came back, ending with the
 * saved image. Only its own line of rounds, never branches that were tried
 * and left.
 *
 * Pictures come from the steps kept in the library (RevisionHistory) and from
 * rounds saved as images of their own, found by their stamps, so moving or
 * renaming any of them changes nothing. A picture the viewer may not see is
 * marked hidden rather than left out, so the numbering still adds up.
 */
class Story
{
    public function __construct(
        protected Threads $threads,
        protected ThreadAssets $assets,
        protected ModelRegistry $models,
        protected Destinations $destinations,
    ) {}

    /**
     * @return array<string, mixed>|null Null when the image has no revision history.
     */
    public function of(AssetContract $saved, $user): ?array
    {
        $line = $this->threads->lineOf($saved);

        if (! $line) {
            return null;
        }

        $stamp = (array) $saved->get(SavedImages::KEY);
        $found = $this->assets->find($line['id']);

        $origin = $found['origin'] ?? (isset($line['origin']['asset']) ? Asset::find($line['origin']['asset']) : null);

        // A saved image keeps only its last rounds (Threads::MAX_ROUNDS), so
        // the first one shown may have been revised from a round left out.
        $parent = $line['rounds'][0]['parent'] ?? null;
        $from = $this->image($parent ? ($found['rounds'][$parent] ?? null) : $origin, $user);
        $last = array_key_last($line['rounds']);
        $steps = [];

        foreach ($line['rounds'] as $index => $round) {
            $result = $this->image($index === $last ? $saved : ($found['rounds'][$round['id']] ?? null), $user);
            $model = $round['model'] ?? null;

            $steps[] = [
                'number' => $index + 1,
                'round' => $round['id'],
                'notes' => $round['notes'] ?? [],
                'general' => $round['general'] ?? null,
                'model' => $model,
                'modelLabel' => $model ? $this->models->label($model) : null,
                'quality' => $round['quality'] ?? null,
                'qualityLabel' => ModelRegistry::qualityLabel($round['quality'] ?? null),
                'memory' => $round['memory'] ?? null,
                'at' => $round['at'] ?? null,
                // The picture the notes were pinned on.
                'from' => $from,
                'result' => $result,
            ];

            $from = $result;
        }

        return [
            'thread' => $line['id'],
            'prompt' => $stamp['prompt'] ?? null,
            'instructionTitle' => $stamp['instruction_title'] ?? null,
            'original' => $this->image($origin, $user),
            'earlier' => (bool) $parent,
            'steps' => $steps,
            'saved' => [
                'id' => $saved->id(),
                'path' => $saved->path(),
                'alt' => $saved->get('alt'),
                'preview' => cp_route('darkroom.assets.preview', ['asset' => $saved->id()]),
                'editUrl' => $saved->editUrl(),
            ],
            // Deleting the history changes the image's own data.
            'canDeleteHistory' => Gate::forUser($user)->allows('edit', $saved),
            'canRevise' => (bool) $this->destinations->find($user, $saved->containerHandle()),
        ];
    }

    /**
     * A step's picture. "kept" when it is a copy in a revisions folder, which
     * deleting the history removes; "saved" when it is an image saved from
     * Darkroom in its own right; "asset" for an original that was already in
     * the library.
     *
     * @return array<string, mixed>|null Null when the picture was not kept.
     */
    protected function image(?AssetContract $asset, $user): ?array
    {
        if (! $asset) {
            return null;
        }

        if (! Gate::forUser($user)->allows('view', $asset)) {
            return ['hidden' => true];
        }

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'preview' => cp_route('darkroom.assets.preview', ['asset' => $asset->id()]),
            'width' => $asset->width(),
            'height' => $asset->height(),
            'kind' => match (true) {
                (bool) $asset->get(ThreadAssets::REVISION_KEY) => 'kept',
                (bool) $asset->get(SavedImages::KEY) => 'saved',
                default => 'asset',
            },
            'trashed' => Trash::isTrashed($asset),
        ];
    }
}
