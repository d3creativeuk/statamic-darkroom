<?php

namespace D3Creative\Darkroom\Instructions;

use D3Creative\Darkroom\Support\YamlStore;

/**
 * Saved system instructions: titled tone and style notes that are sent along
 * with a prompt. One of them can be the default, preselected on page load.
 */
class InstructionStore extends YamlStore
{
    public function __construct(protected ?string $path = null) {}

    protected function path(): string
    {
        return $this->path ?: resource_path('addons/statamic-darkroom/instructions.yaml');
    }

    protected function fields(): array
    {
        return ['title', 'body', 'default'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function default(): ?array
    {
        foreach ($this->all() as $item) {
            if ($item['default'] ?? false) {
                return $item;
            }
        }

        return null;
    }

    protected function normalise(array $item): array
    {
        $item = parent::normalise($item);
        $item['default'] = (bool) $item['default'];

        return $item;
    }

    // Only one instruction can be the default, so marking one unmarks the rest.
    protected function beforeWrite(array $items, array $saved): array
    {
        if (! $saved['default']) {
            return $items;
        }

        return array_map(function ($item) use ($saved) {
            $item['default'] = $item['id'] === $saved['id'];

            return $item;
        }, $items);
    }
}
