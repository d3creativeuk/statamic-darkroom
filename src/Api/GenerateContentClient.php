<?php

namespace D3Creative\Darkroom\Api;

/**
 * The older generateContent endpoint. Still fully supported by Google and
 * kept as a fallback, selected with DARKROOM_API=generate_content.
 */
class GenerateContentClient extends AbstractGeminiClient
{
    protected function url(ImageRequest $request): string
    {
        return rtrim($this->config['base_url'], '/').'/models/'.$request->model.':generateContent';
    }

    public function payload(ImageRequest $request): array
    {
        $image = ['imageSize' => $request->quality];

        if ($request->aspectRatio !== null) {
            $image['aspectRatio'] = $request->aspectRatio;
        }

        $parts = [['text' => $request->input()]];

        foreach ($request->references as $reference) {
            $parts[] = ['inlineData' => [
                'mimeType' => $reference['mime_type'],
                'data' => base64_encode($reference['data']),
            ]];
        }

        $payload = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                // Google's guide also shows a newer "responseFormat.image"
                // block, but that one rejects "1K" and "16:9" and wants enum
                // names instead. imageConfig takes the plain values.
                'imageConfig' => $image,
            ],
        ];

        if ($instruction = $request->nativeInstruction()) {
            $payload['systemInstruction'] = ['parts' => [['text' => $instruction]]];
        }

        return $payload;
    }

    protected function parse(array $json, ImageRequest $request): ImageResult
    {
        $candidate = $json['candidates'][0] ?? [];
        $image = null;
        $text = [];

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            // Parts flagged as thoughts are drafts. The last unflagged image
            // is the finished one.
            if (($part['thought'] ?? false) === true) {
                continue;
            }

            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if (isset($inline['data'])) {
                $image = $inline;
            } elseif (isset($part['text'])) {
                $text[] = $part['text'];
            }
        }

        if ($image === null) {
            throw $this->noImage([
                $json['promptFeedback']['blockReason'] ?? null,
                $candidate['finishReason'] ?? null,
            ], array_merge($text, array_filter([$candidate['finishMessage'] ?? null])), $json);
        }

        return new ImageResult(
            $this->decode($image['data']),
            $image['mimeType'] ?? $image['mime_type'] ?? 'image/jpeg',
            $json['modelVersion'] ?? $request->model,
            array_filter($json['usageMetadata'] ?? [], 'is_scalar'),
        );
    }
}
