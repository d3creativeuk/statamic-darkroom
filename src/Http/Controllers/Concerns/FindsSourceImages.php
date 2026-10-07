<?php

namespace D3Creative\Darkroom\Http\Controllers\Concerns;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\Generations\ItemStatus;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Revisions\RevisionHistory;
use D3Creative\Darkroom\Revisions\ThreadAssets;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The image an upscale or a revision starts from: either one generated and
 * not saved yet, or an asset, normally one listed in History.
 */
trait FindsSourceImages
{
    use FindsBatches;

    /**
     * An image that has been generated but not saved yet.
     *
     * @return array<string, mixed>|Response
     */
    protected function fromItem(BatchStore $store, string $batchId, int $index, string $refusal = 'This image is not available.')
    {
        $batch = $this->ownedBatch($store, $batchId);
        $item = $this->ownedItem($batch, $index);

        if ($item['status'] !== ItemStatus::Complete->value || ! ($binary = $store->original($batchId, $index))) {
            return $this->refuse('not_ready', $refusal);
        }

        return [
            'binary' => $binary,
            'mime' => $item['mime'] ?? 'image/jpeg',
            'prompt' => $batch['prompt'],
            'model' => $batch['model'] ?? null,
            'quality' => $batch['quality'] ?? null,
            'aspect_ratio' => $batch['aspect_ratio'] ?? ModelRegistry::AUTO,
            'file_type' => $batch['file_type'],
            'container' => $batch['container'],
            'folder' => $batch['folder'] ?? '',
            'instruction_id' => $batch['instruction_id'] ?? null,
            'instruction_title' => $batch['instruction_title'] ?? null,
            'references' => $batch['references'] ?? null,
            // Where it came from, so a revision can join its thread.
            'base' => ['batch' => $batch, 'item' => $item],
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
        // A kept step of a revised image (RevisionHistory) is tagged with
        // what made it instead.
        $made = $stamp ?: (array) $asset->get(ThreadAssets::REVISION_KEY);
        $extension = strtolower((string) $asset->extension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return [
            'binary' => $asset->contents(),
            'mime' => $asset->mimeType(),
            'prompt' => $made['prompt'] ?? $asset->basename(),
            'model' => $made['model'] ?? null,
            'quality' => $made['quality'] ?? null,
            'aspect_ratio' => $made['aspect_ratio'] ?? ModelRegistry::AUTO,
            // Saved in the same type as the original where that is one on offer.
            'file_type' => array_key_exists($extension, $config['file_types'] ?? [])
                ? $extension
                : ($config['defaults']['file_type'] ?? 'jpg'),
            'container' => $asset->containerHandle(),
            // A kept step lives in a revisions folder; what is made from it
            // belongs beside the image that folder is for.
            'folder' => $made === $stamp || basename(trim((string) $asset->folder(), '/.')) !== app(RevisionHistory::class)->folderName()
                ? trim((string) $asset->folder(), '/.')
                : trim(dirname(trim((string) $asset->folder(), '/.')), '/.'),
            'instruction_id' => $stamp['instruction'] ?? null,
            'instruction_title' => $stamp['instruction_title'] ?? null,
            'references' => $stamp['references'] ?? null,
            'asset' => $asset,
            'stamp' => $stamp,
        ];
    }
}
