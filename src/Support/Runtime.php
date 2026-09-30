<?php

namespace D3Creative\Darkroom\Support;

/**
 * Loosens PHP's limits for work that runs after the response has been sent.
 * Under PHP-FPM the default 30 second execution limit still applies there,
 * and one image from Google can take longer than that on its own.
 */
class Runtime
{
    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public static function extend(array $config, int $seconds): void
    {
        @set_time_limit($seconds);

        $wanted = (string) ($config['memory_limit'] ?? '');
        $current = (string) ini_get('memory_limit');

        // Only ever raise it. -1 means unlimited, which is already enough.
        if ($wanted !== '' && $current !== '-1' && self::bytes($wanted) > self::bytes($current)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
