<?php

namespace D3Creative\Darkroom\Revisions;

/**
 * The prompt that asks the model to change an image it is given.
 *
 * Each note says where it applies in words, as a share of the width and
 * height. Tested against Nano Banana Pro on 2 October 2026: this changed only
 * what was asked, where sending a copy with numbered markers drawn on it as
 * well also removed things nobody had pinned.
 */
class RevisionPrompt
{
    public const MAX_NOTES = 10;

    /**
     * @param  array{notes?: array<int, array{x: float, y: float, text: string}>, general?: ?string}  $revision
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public static function build(array $revision, array $config): string
    {
        $changes = array_map(
            fn (array $note) => sprintf('At %s: %s', self::where($note), self::sentence($note['text'])),
            $revision['notes'] ?? [],
        );

        if (filled($revision['general'] ?? null)) {
            $changes[] = 'Across the whole image: '.self::sentence($revision['general']);
        }

        $numbered = array_map(fn ($change, $i) => ($i + 1).'. '.$change, $changes, array_keys($changes));

        return implode("\n", [
            (string) ($config['revise']['intro'] ?? 'Edit this image. Make only these changes:'),
            ...$numbered,
            (string) ($config['revise']['keep'] ?? 'Keep everything else exactly as it is: the composition, subject, colours, textures and style.'),
        ]);
    }

    /**
     * "about 74% from the left and 81% from the top".
     *
     * @param  array{x: float, y: float}  $note
     */
    public static function where(array $note): string
    {
        return sprintf(
            'about %d%% from the left and %d%% from the top',
            (int) round(max(0, min(1, (float) $note['x'])) * 100),
            (int) round(max(0, min(1, (float) $note['y'])) * 100),
        );
    }

    protected static function sentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return preg_match('/[.!?]$/', $text) ? $text : $text.'.';
    }
}
