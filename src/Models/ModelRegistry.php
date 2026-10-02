<?php

namespace D3Creative\Darkroom\Models;

use D3Creative\Darkroom\Api\ImageRequest;

/**
 * The image models from config, and what each one can do. Everything that
 * depends on the chosen model (the menus, validation, price, how a system
 * instruction is delivered) reads from here.
 */
class ModelRegistry
{
    public const AUTO = 'auto';

    // Every size the API knows, smallest first, with how it relates to 1K.
    // The API calls the smallest "512"; everyone else calls it 0.5K.
    protected const SIZES = ['512' => 0.5, '1K' => 1, '2K' => 2, '4K' => 4];

    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->config['models'] ?? []);
    }

    public function has(?string $id): bool
    {
        return $id !== null && isset($this->config['models'][$id]);
    }

    public function defaultId(): string
    {
        $default = $this->config['default_model'] ?? null;

        return $this->has($default) ? $default : ($this->ids()[0] ?? '');
    }

    public function label(string $id): string
    {
        return $this->config['models'][$id]['label'] ?? $id;
    }

    public function description(string $id): ?string
    {
        return $this->config['models'][$id]['description'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function qualities(string $id): array
    {
        return array_map('strval', array_keys($this->config['models'][$id]['qualities'] ?? []));
    }

    /**
     * @return array<int, string>
     */
    public function aspectRatios(string $id): array
    {
        return array_values($this->config['models'][$id]['aspect_ratios'] ?? []);
    }

    /**
     * Price of one image in USD, or null when the config does not say.
     */
    public function price(string $id, string $quality): ?float
    {
        $price = $this->config['models'][$id]['qualities'][$quality] ?? null;

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * "native" or "prepend". The global override wins when it is set.
     */
    public function instructionMode(string $id): string
    {
        $mode = $this->config['system_instruction_mode'] ?? null
            ?: ($this->config['models'][$id]['system_instruction'] ?? 'native');

        return $mode === 'prepend' ? 'prepend' : 'native';
    }

    /**
     * Expected pixel size, or null for Auto and for models with no table.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $id, string $aspectRatio, string $quality): ?array
    {
        $table = $this->config['models'][$id]['dimensions'] ?? [];

        // A size Google has been measured at wins. Anything else is worked
        // out from 1K, which is exact for 2K and 4K.
        if (is_array($exact = $table[$quality][$aspectRatio] ?? null)) {
            return [(int) $exact[0], (int) $exact[1]];
        }

        $base = $table['1K'][$aspectRatio] ?? null;
        $scale = self::SIZES[$quality] ?? null;

        if (! is_array($base) || $scale === null) {
            return null;
        }

        return [(int) ($base[0] * $scale), (int) ($base[1] * $scale)];
    }

    /**
     * How a quality is shown to people.
     */
    public static function qualityLabel(?string $quality): ?string
    {
        return $quality === '512' ? '0.5K' : $quality;
    }

    /**
     * Where a quality sits from smallest to largest, or null if unrecognised.
     */
    public static function rank(?string $quality): ?int
    {
        $rank = array_search((string) $quality, array_map('strval', array_keys(self::SIZES)), true);

        return $rank === false ? null : $rank;
    }

    /**
     * @param  array<int, array{mime_type: string, data: string}>  $references  Images to send with the prompt.
     */
    public function request(string $id, string $prompt, string $quality, ?string $aspectRatio, ?string $instruction, array $references = [], ?string $continues = null, bool $store = false): ImageRequest
    {
        return new ImageRequest(
            prompt: $prompt,
            model: $id,
            quality: $quality,
            aspectRatio: $aspectRatio === self::AUTO ? null : $aspectRatio,
            systemInstruction: $instruction,
            prependInstruction: $this->instructionMode($id) === 'prepend',
            references: $references,
            continues: $continues,
            store: $store,
        );
    }

    /**
     * Everything the page needs to build its menus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forFrontend(): array
    {
        return array_map(fn (string $id) => [
            'id' => $id,
            'label' => $this->label($id),
            'description' => $this->description($id),
            'qualities' => array_map(fn (string $quality) => [
                'value' => $quality,
                'label' => self::qualityLabel($quality),
                'price' => $this->price($id, $quality),
            ], $this->qualities($id)),
            'aspectRatios' => $this->aspectRatios($id),
            'dimensions' => $this->config['models'][$id]['dimensions'] ?? null,
            'prependsInstruction' => $this->instructionMode($id) === 'prepend',
        ], $this->ids());
    }
}
