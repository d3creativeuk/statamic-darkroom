<?php

namespace D3Creative\Darkroom;

use D3Creative\Darkroom\Api\AltTextWriter;
use D3Creative\Darkroom\Api\GenerateContentClient;
use D3Creative\Darkroom\Api\ImageGenerator;
use D3Creative\Darkroom\Api\InteractionsClient;
use D3Creative\Darkroom\Assets\AssetSaver;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Imaging\ImageEncoder;
use D3Creative\Darkroom\Instructions\InstructionStore;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Prompts\PromptStore;
use D3Creative\Darkroom\Usage\UsageLog;
use Illuminate\Console\Scheduling\Schedule;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'publicDirectory' => 'public',
        'buildDirectory' => 'build',
        'input' => ['resources/js/addon.js'],
    ];

    public function register()
    {
        parent::register();

        // These all read the addon's config, which Statamic merges during
        // boot. Resolving them lazily means they see the merged values and
        // anything the site has overridden.
        $config = fn () => (array) config('statamic-darkroom');

        $this->app->bind(ImageGenerator::class, fn () => $config()['api'] === 'generate_content'
            ? new GenerateContentClient($config())
            : new InteractionsClient($config()));

        $this->app->bind(ModelRegistry::class, fn () => new ModelRegistry($config()));
        $this->app->bind(BatchStore::class, fn () => new BatchStore($config()));
        $this->app->bind(AssetSaver::class, fn ($app) => new AssetSaver($app->make(ImageEncoder::class), $config()));
        $this->app->bind(PromptStore::class, fn () => new PromptStore($config()['prompts']['path'] ?? null));
        $this->app->bind(InstructionStore::class, fn () => new InstructionStore($config()['instructions']['path'] ?? null));
        $this->app->bind(UsageLog::class, fn () => new UsageLog($config()));
        $this->app->bind(AltTextWriter::class, fn () => new AltTextWriter($config()));
        $this->app->bind(SavedImages::class, fn ($app) => new SavedImages($app->make(ModelRegistry::class), $config()));
    }

    public function bootAddon()
    {
        Permission::extend(function () {
            Permission::group('darkroom', 'Darkroom', function () {
                // Spend lists every prompt behind it, so seeing other people's
                // is a separate permission. Super users have it anyway.
                Permission::register('use darkroom', function ($permission) {
                    $permission->children([
                        Permission::make(UsageLog::VIEW_ALL)->label('See everyone\'s spend in Darkroom'),
                    ]);
                })->label('Generate images with Darkroom');
            });
        });

        Nav::extend(function ($nav) {
            $darkroom = fn () => $nav->tools('Darkroom')
                ->route('darkroom.index')
                ->icon('ai-sparks')
                ->can('use darkroom');

            // Statamic lists each addon's settings page under Tools > Addons,
            // so that is where people look for anything an addon adds. It only
            // creates that item for users who may configure addons, though.
            // Everyone else gets Darkroom as an item of its own, or they would
            // have no way in.
            if (! ($addons = $nav->find('Tools', 'Addons'))) {
                $darkroom();

                return;
            }

            $existing = $addons->children();

            $addons->children(fn () => collect(is_callable($existing) ? $existing() : $existing)
                ->push($darkroom())
                ->sortBy(fn ($item) => strtolower($item->display()))
                ->values());
        });
    }

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('darkroom:prune')->daily();
    }
}
