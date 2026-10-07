<?php

namespace D3Creative\Darkroom\Prompts;

use D3Creative\Darkroom\Support\YamlStore;

/**
 * Saved prompts, each with the settings it was written for.
 *
 * Batch size is left out on purpose: loading a prompt should never quietly
 * multiply what a click costs.
 */
class PromptStore extends YamlStore
{
    public function __construct(protected ?string $path = null) {}

    protected function path(): string
    {
        return $this->path ?: resource_path('addons/statamic-darkroom/prompts.yaml');
    }

    protected function fields(): array
    {
        // "references" holds asset ids only: an uploaded reference is never
        // kept, so it cannot be brought back with the prompt.
        return ['name', 'prompt', 'model', 'instruction', 'aspect_ratio', 'quality', 'file_type', 'container', 'folder', 'references'];
    }
}
