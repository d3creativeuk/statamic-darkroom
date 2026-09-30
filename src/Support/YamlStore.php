<?php

namespace D3Creative\Darkroom\Support;

use Illuminate\Support\Str;
use Statamic\Facades\YAML;

/**
 * A small list of records kept in one YAML file, for things an editor saves
 * and reuses: prompts and system instructions.
 *
 * These deliberately do not live in Statamic's addon settings. Settings run
 * every string through Antlers on load, so a prompt that happened to contain
 * "{{ }}" would be evaluated as a template. Here the text is stored and
 * returned exactly as typed.
 */
abstract class YamlStore
{
    abstract protected function path(): string;

    /**
     * The keys a record may hold, in the order they are written.
     *
     * @return array<int, string>
     */
    abstract protected function fields(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $items = YAML::parse(file_get_contents($this->path()));

        return array_values(array_filter(
            is_array($items) ? $items : [],
            fn ($item) => is_array($item) && isset($item['id']),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach ($this->all() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Create the record, or replace the one with the same id.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function save(array $item): array
    {
        $item = $this->normalise($item);
        $items = $this->all();
        $replaced = false;

        foreach ($items as $i => $existing) {
            if ($existing['id'] === $item['id']) {
                $items[$i] = $item;
                $replaced = true;
            }
        }

        if (! $replaced) {
            $items[] = $item;
        }

        $this->write($this->beforeWrite($items, $item));

        return $item;
    }

    public function delete(string $id): bool
    {
        $items = $this->all();
        $remaining = array_values(array_filter($items, fn ($item) => $item['id'] !== $id));

        if (count($remaining) === count($items)) {
            return false;
        }

        $this->write($remaining);

        return true;
    }

    /**
     * A last look at the whole list before it is written, for rules that span
     * records.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $saved
     * @return array<int, array<string, mixed>>
     */
    protected function beforeWrite(array $items, array $saved): array
    {
        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalise(array $item): array
    {
        $normalised = ['id' => $item['id'] ?? strtolower((string) Str::ulid())];

        foreach ($this->fields() as $field) {
            $normalised[$field] = $item[$field] ?? null;
        }

        return $normalised;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function write(array $items): void
    {
        AtomicFile::put($this->path(), $items === [] ? "[]\n" : YAML::dump(array_values($items)));
    }
}
