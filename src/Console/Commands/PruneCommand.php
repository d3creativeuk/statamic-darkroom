<?php

namespace D3Creative\Darkroom\Console\Commands;

use D3Creative\Darkroom\Generations\BatchStore;
use D3Creative\Darkroom\History\Trash;
use D3Creative\Darkroom\Revisions\RevisionHistory;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'darkroom:prune
        {--hours= : Remove batches older than this many hours instead of the configured retention}
        {--days= : Empty trash older than this many days instead of the configured retention}';

    protected $description = 'Remove generated images that were never saved or discarded, and empty old trash';

    public function handle(BatchStore $store, Trash $trash, RevisionHistory $revisions): int
    {
        $hours = $this->option('hours');
        $days = $this->option('days');

        $revisions->backfill();

        $pruned = $store->prune(is_numeric($hours) ? (int) $hours : null);

        $this->info("Pruned {$pruned} ".($pruned === 1 ? 'batch' : 'batches').'.');

        $emptied = $trash->purge(is_numeric($days) ? (int) $days : null);

        $this->info("Deleted {$emptied['deleted']} trashed ".($emptied['deleted'] === 1 ? 'image' : 'images').'.');

        if ($emptied['kept']) {
            $this->info("Kept {$emptied['kept']} still used on the site, and stopped listing ".($emptied['kept'] === 1 ? 'it' : 'them').' in Darkroom.');
        }

        return self::SUCCESS;
    }
}
