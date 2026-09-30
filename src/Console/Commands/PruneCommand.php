<?php

namespace D3Creative\Darkroom\Console\Commands;

use D3Creative\Darkroom\Generations\BatchStore;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'darkroom:prune {--hours= : Remove batches older than this many hours instead of the configured retention}';

    protected $description = 'Remove generated images that were never saved or discarded';

    public function handle(BatchStore $store): int
    {
        $hours = $this->option('hours');

        $pruned = $store->prune(is_numeric($hours) ? (int) $hours : null);

        $this->info("Pruned {$pruned} ".($pruned === 1 ? 'batch' : 'batches').'.');

        return self::SUCCESS;
    }
}
