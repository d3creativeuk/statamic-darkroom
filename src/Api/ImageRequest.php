<?php

namespace D3Creative\Darkroom\Api;

/**
 * One image to generate. A batch is several of these with the same values.
 */
final readonly class ImageRequest
{
    /**
     * @param  ?string  $aspectRatio  Null lets the model choose ("Auto").
     * @param  bool  $prependInstruction  For models that ignore the API's system
     *                                    instruction field: put the instruction
     *                                    ahead of the prompt instead.
     * @param  array<int, array{mime_type: string, data: string}>  $references  Images sent
     *                                                                          with the prompt, as raw bytes.
     * @param  ?string  $continues  An Interactions conversation to carry on, so the
     *                              model sees the earlier turns.
     * @param  bool  $store  Keep this turn on Google's side, so it can be carried on.
     */
    public function __construct(
        public string $prompt,
        public string $model,
        public string $quality = '1K',
        public ?string $aspectRatio = null,
        public ?string $systemInstruction = null,
        public bool $prependInstruction = false,
        public array $references = [],
        public ?string $continues = null,
        public bool $store = false,
    ) {}

    /**
     * The text sent as the prompt, with the instruction in front when the
     * model needs it there.
     */
    public function input(): string
    {
        if ($this->prependInstruction && $this->hasInstruction()) {
            return trim($this->systemInstruction)."\n\n".$this->prompt;
        }

        return $this->prompt;
    }

    /**
     * The instruction to send in the API's own field, or null when there is
     * none or it has been folded into the prompt.
     */
    public function nativeInstruction(): ?string
    {
        return $this->hasInstruction() && ! $this->prependInstruction
            ? trim($this->systemInstruction)
            : null;
    }

    private function hasInstruction(): bool
    {
        return $this->systemInstruction !== null && trim($this->systemInstruction) !== '';
    }
}
