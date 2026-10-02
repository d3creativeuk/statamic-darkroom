<?php

namespace D3Creative\Darkroom\Usage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A record of every image Google has returned, for seeing what was spent.
 *
 * Google charges when an image is generated, whether or not it is then saved,
 * so entries are written at that moment and never removed. Each holds the
 * list price from config at the time. That makes the totals an estimate of
 * the bill, not the bill: Google's invoice also counts the few text tokens in
 * each request, and its prices can change before the config is updated.
 *
 * One file per month, one JSON object per line, so a new entry is a single
 * append and two PHP workers finishing at once cannot lose each other's line.
 */
class UsageLog
{
    public const VIEW_ALL = 'view all darkroom spend';

    /**
     * @param  array<string, mixed>  $config  The statamic-darkroom config array.
     */
    public function __construct(protected array $config) {}

    /**
     * Recording must never be the reason a generation fails, so nothing here
     * is allowed to throw.
     *
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        try {
            $now = now();

            $line = json_encode(array_merge($entry, [
                'at' => $now->getTimestamp(),
                'price' => is_numeric($entry['price'] ?? null) ? (float) $entry['price'] : null,
                'prompt' => Str::limit((string) ($entry['prompt'] ?? ''), 140),
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $path = $this->disk()->path($this->file($now->format('Y-m')));

            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whose usage a user may see: null for everyone's, or their own ID.
     * Entries from the command line carry no user, so only someone allowed to
     * see everyone's sees those.
     */
    public static function scopeFor($user): ?string
    {
        return Gate::forUser($user)->allows(self::VIEW_ALL) ? null : (string) $user->id();
    }

    /**
     * A summary of each month that has any usage, newest first.
     *
     * @param  string|null  $user  Only this user's entries, or null for everyone's.
     * @return array<int, array<string, mixed>>
     */
    public function months(int $limit = 12, ?string $user = null): array
    {
        return collect($this->disk()->files($this->root()))
            ->map(fn ($file) => basename($file, '.jsonl'))
            ->filter(fn ($month) => self::validMonth($month))
            ->sortDesc()
            ->map(fn ($month) => $this->summarise($month, $user))
            // A month file can hold nothing of this user's.
            ->filter(fn ($summary) => $summary['images'] + $summary['altTexts'] > 0)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Every entry for a month, newest first.
     *
     * @param  string|null  $user  Only this user's entries, or null for everyone's.
     * @return array<int, array<string, mixed>>
     */
    public function entries(string $month, ?string $user = null): array
    {
        if (! self::validMonth($month) || ! $this->disk()->exists($this->file($month))) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", (string) $this->disk()->get($this->file($month))) as $line) {
            $entry = $line === '' ? null : json_decode($line, true);

            if (is_array($entry) && ($user === null || ($entry['user'] ?? null) === $user)) {
                $entries[] = $entry;
            }
        }

        return array_reverse($entries);
    }

    public static function validMonth(string $month): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summarise(string $month, ?string $user = null): array
    {
        $entries = collect($this->entries($month, $user));

        // Alt text calls cost money too, so they count towards the total,
        // but they are not images and are counted separately.
        $alt = fn ($entry) => ($entry['kind'] ?? null) === 'alt_text';

        return [
            'month' => $month,
            'label' => Carbon::createFromFormat('!Y-m', $month)->format('F Y'),
            'images' => $entries->reject($alt)->count(),
            'altTexts' => $entries->filter($alt)->count(),
            'total' => round($entries->sum('price'), 4),
            // True when some entries had no price in config, so the total is
            // known to be short.
            'unpriced' => $entries->contains(fn ($entry) => ($entry['price'] ?? null) === null),
            'models' => $entries
                ->groupBy(fn ($entry) => $entry['model_label'] ?? $entry['model'] ?? 'Unknown')
                ->map(fn ($group, $label) => [
                    'label' => $label,
                    'images' => $group->count(),
                    'total' => round($group->sum('price'), 4),
                ])
                ->sortByDesc('total')
                ->values()
                ->all(),
        ];
    }

    protected function disk(): Filesystem
    {
        return Storage::disk($this->config['usage']['disk'] ?? 'local');
    }

    protected function root(): string
    {
        return trim($this->config['usage']['path'] ?? 'statamic-darkroom/usage', '/');
    }

    protected function file(string $month): string
    {
        return $this->root().'/'.$month.'.jsonl';
    }
}
