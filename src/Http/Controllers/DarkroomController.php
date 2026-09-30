<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Instructions\InstructionStore;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Prompts\PromptStore;
use D3Creative\Darkroom\Usage\UsageLog;
use Inertia\Inertia;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class DarkroomController extends CpController
{
    /**
     * The folders in a container, for finding one by name in the folder
     * picker. Core's asset search only ever matches files.
     */
    public function folders(string $container, Destinations $destinations)
    {
        abort_unless($found = $destinations->find(User::current(), $container), 403);

        return response()->json(['folders' => $destinations->folders($found)]);
    }

    public function index(
        ModelRegistry $models,
        Destinations $destinations,
        PromptStore $prompts,
        InstructionStore $instructions,
        BatchStore $batches,
        BatchPresenter $presenter,
        SavedImages $history,
        UsageLog $usage,
    ) {
        $batches->prune();

        $user = User::current();
        $config = config('statamic-darkroom');
        $containers = $destinations->forFrontend($user);
        $handles = array_column($containers, 'handle');

        $container = in_array($config['defaults']['container'] ?? null, $handles, true)
            ? $config['defaults']['container']
            : ($handles[0] ?? null);

        return Inertia::render('darkroom/Index', [
            // The key itself never leaves the server. The page only needs to
            // know whether there is one.
            'configured' => filled($config['api_key'] ?? null),
            'models' => $models->forFrontend(),
            'fileTypes' => collect($config['file_types'] ?? [])
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'containers' => $containers,
            'defaults' => [
                'model' => $models->defaultId(),
                'aspectRatio' => $config['defaults']['aspect_ratio'] ?? '16:9',
                'quality' => $config['defaults']['quality'] ?? '2K',
                'fileType' => $config['defaults']['file_type'] ?? 'jpg',
                'container' => $container,
                'folder' => Destinations::safeFolder($config['defaults']['folder'] ?? ''),
                'instruction' => $instructions->default()['id'] ?? null,
            ],
            'limits' => [
                'batchMax' => (int) ($config['batch']['max'] ?? 4),
                'prompt' => (int) ($config['prompts']['max_length'] ?? 8000),
                'instruction' => (int) ($config['instructions']['max_length'] ?? 8000),
                'pollInterval' => (int) ($config['poll_interval'] ?? 2000),
            ],
            'prompts' => $prompts->all(),
            'instructions' => $instructions->all(),
            'batches' => array_map([$presenter, 'present'], $batches->openFor((string) $user->id())),
            'history' => $history->page($user),
            'usage' => $usage->months(),
            'urls' => [
                'batches' => cp_route('darkroom.batches.store'),
                'upscales' => cp_route('darkroom.upscales.store'),
                'folders' => cp_route('darkroom.folders', '__container__'),
                'prompts' => cp_route('darkroom.prompts.store'),
                'instructions' => cp_route('darkroom.instructions.store'),
                'history' => cp_route('darkroom.history.index'),
                'usage' => cp_route('darkroom.usage.index'),
            ],
        ]);
    }
}
