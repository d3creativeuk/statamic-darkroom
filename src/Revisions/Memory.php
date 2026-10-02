<?php

namespace D3Creative\Darkroom\Revisions;

use D3Creative\Darkroom\Exceptions\ApiRequestFailed;
use D3Creative\Darkroom\Exceptions\DarkroomException;

/**
 * Whether a revision round can carry on the conversation of the round it
 * starts from, so the model sees the earlier rounds and a note can say "undo
 * that". Checked against the live API on 2 October 2026: every model can
 * continue a conversation, even one another model started, at any size, and
 * remembering costs no more than a fresh round.
 *
 * Google keeps a stored conversation for the project's retention period (up
 * to 55 days). One that is gone is answered with a 404, and an id it cannot
 * read with a 400; either way the round is sent again as a fresh one, with
 * the image, which is how every round worked before.
 */
class Memory
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public static function enabled(array $config): bool
    {
        // Only the Interactions API keeps conversations.
        return (bool) ($config['revise']['remember'] ?? true) && ($config['api'] ?? 'interactions') !== 'generate_content';
    }

    /**
     * The conversation to carry on, or null to start fresh.
     *
     * @param  array<string, mixed>|null  $candidate  {continues, since}, as recorded on the batch.
     * @param  array<string, mixed>  $config
     */
    public static function usable(?array $candidate, array $config): ?string
    {
        $id = $candidate['continues'] ?? null;

        if (! self::enabled($config) || ! is_string($id) || ! self::validId($id)) {
            return null;
        }

        $days = (int) ($config['revise']['remember_days'] ?? 50);
        $since = (int) ($candidate['since'] ?? 0);

        return $since > now()->subDays($days)->getTimestamp() ? $id : null;
    }

    /**
     * Whether a failed round failed because its conversation is no longer
     * there to carry on.
     */
    public static function lost(DarkroomException $e): bool
    {
        return $e instanceof ApiRequestFailed && in_array($e->status, [400, 404], true);
    }

    /**
     * What Google's interaction ids look like: "v1_" and URL-safe base64.
     */
    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,255}$/', $id);
    }
}
