<?php

namespace D3Creative\Darkroom\Generations;

use D3Creative\Darkroom\Models\ModelRegistry;
use Illuminate\Support\Str;

/**
 * A batch as the page sees it: no file paths, no instruction text, and every
 * URL it needs already built.
 */
class BatchPresenter
{
    public function __construct(protected BatchStore $store, protected ModelRegistry $models) {}

    /**
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function present(array $batch): array
    {
        $id = $batch['id'];
        $count = (int) $batch['count'];

        return [
            'id' => $id,
            'prompt' => $batch['prompt'],
            'model' => $batch['model'],
            'modelLabel' => $this->models->label($batch['model']),
            'quality' => $batch['quality'],
            'qualityLabel' => ModelRegistry::qualityLabel($batch['quality']),
            // Set when this batch is a larger version of an existing image.
            'upscaledFrom' => ($batch['kind'] ?? null) === 'upscale'
                ? (ModelRegistry::qualityLabel($batch['upscaled_from'] ?? null) ?? true)
                : null,
            // The notes, when this batch is a changed version of an existing image.
            'revision' => ($batch['kind'] ?? null) === 'revise' ? ($batch['revision'] ?? null) : null,
            // Which thread a revision belongs to, and how many rounds led to it.
            'thread' => ($thread = BatchStore::threadOf($batch)) ? [
                'id' => $thread,
                'parent' => $batch['thread']['parent'] ?? null,
                'depth' => count($batch['thread']['ancestors'] ?? []) + 1,
            ] : null,
            'aspectRatio' => $batch['aspect_ratio'],
            'fileType' => $batch['file_type'],
            'container' => $batch['container'],
            'folder' => $batch['folder'] ?? '',
            'instructionTitle' => $batch['instruction_title'] ?? null,
            'createdAt' => $batch['created_at'],
            'urls' => [
                'show' => cp_route('darkroom.batches.show', $id),
                'destroy' => cp_route('darkroom.batches.destroy', $id),
            ],
            'items' => array_map(fn (array $item) => [
                'index' => $item['index'],
                'status' => $item['status'],
                'attempts' => $item['attempts'] ?? 0,
                'error' => $item['error'] ?? null,
                'saveError' => $item['save_error'] ?? null,
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
                'bytes' => $item['bytes'] ?? null,
                // For a revision round: "continued" when the model remembered
                // the earlier rounds, "lost" when that conversation had gone.
                'memory' => $item['memory'] ?? null,
                // Steps of a saved revision whose image had already gone.
                'historyMissing' => $item['history_missing'] ?? null,
                'asset' => $item['asset'] ?? null,
                'filename' => $this->filename($batch['prompt'], $item['index'], $count, $this->suffix($batch)),
                'urls' => [
                    // The timestamp busts the browser's copy after a retry
                    // replaces the image behind the same URL.
                    'preview' => $this->store->hasPreview($id, $item['index'])
                        ? cp_route('darkroom.items.preview', [$id, $item['index']]).'?v='.($item['updated_at'] ?? 0)
                        : null,
                    'save' => cp_route('darkroom.items.save', [$id, $item['index']]),
                    'retry' => cp_route('darkroom.items.retry', [$id, $item['index']]),
                    'alt' => cp_route('darkroom.items.alt', [$id, $item['index']]),
                    'destroy' => cp_route('darkroom.items.destroy', [$id, $item['index']]),
                ],
            ], $batch['items']),
        ];
    }

    /**
     * A starting filename from the first few words of the prompt, numbered
     * when the batch has more than one image.
     */
    public function filename(string $prompt, int $index, int $count, string $suffix = ''): string
    {
        $base = Str::limit(Str::slug(Str::words($prompt, 6, '')), 60, '');
        $base = (trim($base, '-') ?: 'darkroom').$suffix;

        return $count > 1 ? "{$base}-{$index}" : $base;
    }

    /**
     * An upscale or a revision is usually saved beside the image it came
     * from, under the same prompt. Naming it after its size, or as revised,
     * keeps the two apart.
     *
     * @param  array<string, mixed>  $batch
     */
    public function suffix(array $batch): string
    {
        return match ($batch['kind'] ?? null) {
            'upscale' => '-'.strtolower((string) $batch['quality']),
            'revise' => '-revised',
            default => '',
        };
    }
}
