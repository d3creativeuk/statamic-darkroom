<?php

namespace D3Creative\Darkroom\Api;

/**
 * Google's Interactions API, the endpoint it recommends for new work.
 */
class InteractionsClient extends AbstractGeminiClient
{
    protected function url(ImageRequest $request): string
    {
        return rtrim($this->config['base_url'], '/').'/interactions';
    }

    public function payload(ImageRequest $request): array
    {
        $format = ['type' => 'image', 'image_size' => $request->quality];

        if ($request->aspectRatio !== null) {
            $format['aspect_ratio'] = $request->aspectRatio;
        }

        // Text alone goes as a string. With images, the input becomes a list
        // of parts: the text first, then each image.
        $input = $request->input();

        if ($request->references !== []) {
            $input = [['type' => 'text', 'text' => $input]];

            foreach ($request->references as $reference) {
                $input[] = [
                    'type' => 'image',
                    'mime_type' => $reference['mime_type'],
                    'data' => base64_encode($reference['data']),
                ];
            }
        }

        $payload = [
            'model' => $request->model,
            'input' => $input,
            'response_format' => $format,
            // Interactions are kept on Google's side by default. Only revision
            // rounds are, so a later round can carry the conversation on;
            // everything else leaves nothing behind.
            'store' => $request->store,
        ];

        // The earlier turns, images included, are already on Google's side.
        if ($request->continues !== null) {
            $payload['previous_interaction_id'] = $request->continues;
        }

        if ($instruction = $request->nativeInstruction()) {
            $payload['system_instruction'] = $instruction;
        }

        return $payload;
    }

    protected function parse(array $json, ImageRequest $request): ImageResult
    {
        $image = null;
        $text = [];
        $reasons = [$json['status'] ?? null];

        foreach ($json['steps'] ?? [] as $step) {
            $reasons[] = $step['finish_reason'] ?? $step['status'] ?? null;

            // "thought" steps are the model's working and can hold draft
            // images. Only a model_output step carries the finished one.
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'image' && isset($block['data'])) {
                    $image = $block;
                } elseif (isset($block['text'])) {
                    $text[] = $block['text'];
                }
            }
        }

        if ($image === null) {
            throw $this->noImage($reasons, $text, $json);
        }

        return new ImageResult(
            $this->decode($image['data']),
            $image['mime_type'] ?? 'image/jpeg',
            $json['model'] ?? $request->model,
            $this->usage($json['usage'] ?? [], 'input_tokens_by_modality', 'modality', 'tokens'),
            // Only present when the turn was stored.
            is_string($json['id'] ?? null) ? $json['id'] : null,
        );
    }
}
