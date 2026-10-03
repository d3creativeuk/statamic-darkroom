<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Assets\Destinations;
use D3Creative\Darkroom\Generations\BatchPresenter;
use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Trash;
use D3Creative\Darkroom\Instructions\InstructionStore;
use D3Creative\Darkroom\Models\ModelRegistry;
use D3Creative\Darkroom\Prompts\PromptStore;
use D3Creative\Darkroom\Revisions\RevisionHistory;
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
        Trash $trash,
        RevisionHistory $revisions,
    ) {
        // Before pruning, so rounds still in temporary storage can fill in
        // the history of images saved before it was kept. Once only.
        $revisions->backfill();

        $batches->prune();

        // As with batches, a site without the scheduler still has its trash
        // emptied whenever the page is opened. Content is only read when
        // something has actually expired.
        $trash->purge();

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
            'trash' => $trash->list($user),
            'trashDays' => $trash->retentionDays(),
            // Whether saving a revised image keeps its earlier steps, and where.
            'revisionHistory' => $revisions->enabled() ? ['folder' => $revisions->folderName()] : null,
            'usage' => $usage->months(user: UsageLog::scopeFor($user)),
            'usageIsEveryones' => UsageLog::scopeFor($user) === null,
            'urls' => [
                'batches' => cp_route('darkroom.batches.store'),
                'upscales' => cp_route('darkroom.upscales.store'),
                'revisions' => cp_route('darkroom.revisions.store'),
                'threads' => cp_route('darkroom.threads.show', '__thread__'),
                'assetPreview' => cp_route('darkroom.assets.preview'),
                'story' => cp_route('darkroom.story.show'),
                'storyForget' => cp_route('darkroom.story.forget'),
                'folders' => cp_route('darkroom.folders', '__container__'),
                'prompts' => cp_route('darkroom.prompts.store'),
                'instructions' => cp_route('darkroom.instructions.store'),
                'history' => cp_route('darkroom.history.index'),
                'forget' => cp_route('darkroom.history.forget'),
                'trash' => cp_route('darkroom.trash.index'),
                'restore' => cp_route('darkroom.trash.restore'),
                'destroy' => cp_route('darkroom.trash.destroy'),
                'usages' => cp_route('darkroom.usages'),
                'usage' => cp_route('darkroom.usage.index'),
            ],
        ]);
    }
}
