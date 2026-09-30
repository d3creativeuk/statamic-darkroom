<?php

namespace D3Creative\Darkroom\Console\Commands;

use D3Creative\Darkroom\Api\GenerateContentClient;
use D3Creative\Darkroom\Api\ImageGenerator;
use D3Creative\Darkroom\Api\InteractionsClient;
use D3Creative\Darkroom\Exceptions\DarkroomException;
use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SmokeCommand extends Command
{
    protected $signature = 'darkroom:smoke
        {prompt : What to generate}
        {--model= : Model ID, defaults to the configured default}
        {--quality=1K : 1K, 2K or 4K}
        {--ratio=1:1 : Aspect ratio, or "auto"}
        {--instruction= : A system instruction to send with the prompt}
        {--api= : "interactions" or "generate_content", defaults to the configured API}';

    protected $description = 'Generate one real image to check the API key and connection. This costs money.';

    public function handle(ModelRegistry $models, ImageEncoder $encoder, UsageLog $usage): int
    {
        $config = config('statamic-darkroom');
        $model = $this->option('model') ?: $models->defaultId();

        if (! $models->has($model)) {
            $this->error("Unknown model \"{$model}\". Configured models: ".implode(', ', $models->ids()));

            return self::FAILURE;
        }

        $request = $models->request(
            $model,
            $this->argument('prompt'),
            $this->option('quality'),
            $this->option('ratio'),
            $this->option('instruction'),
        );

        $started = microtime(true);

        try {
            $result = $this->generator($config)->generate($request);
        } catch (DarkroomException $e) {
            $this->error($e->errorCode().': '.$e->getMessage());

            return self::FAILURE;
        }

        // A smoke test is billed like any other image, so it belongs in the
        // spend figures too.
        $usage->record([
            'source' => 'smoke',
            'model' => $model,
            'model_label' => $models->label($model),
            'quality' => $this->option('quality'),
            'aspect_ratio' => $this->option('ratio'),
            'price' => $models->price($model, $this->option('quality')),
            'prompt' => $this->argument('prompt'),
        ]);

        [$width, $height] = $encoder->dimensions($result->binary);

        $disk = Storage::disk($config['temp']['disk'] ?? 'local');
        $path = 'statamic-darkroom/smoke/'.date('Ymd-His').'.'.$result->extension();
        $disk->put($path, $result->binary);

        $this->table([], [
            ['Model', $result->model],
            ['Type', $result->mimeType],
            ['Size', "{$width}x{$height}"],
            ['Bytes', number_format(strlen($result->binary))],
            ['Seconds', round(microtime(true) - $started, 1)],
            ['Saved to', $disk->path($path)],
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function generator(array $config): ImageGenerator
    {
        return match ($this->option('api')) {
            'interactions' => new InteractionsClient($config),
            'generate_content' => new GenerateContentClient($config),
            default => app(ImageGenerator::class),
        };
    }
}
