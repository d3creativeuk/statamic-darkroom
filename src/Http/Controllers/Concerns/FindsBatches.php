<?php

namespace D3Creative\Darkroom\Http\Controllers\Concerns;

use D3Creative\Darkroom\Generations\BatchStore;
use Illuminate\Http\JsonResponse;
use Statamic\Facades\User;

trait FindsBatches
{
    /**
     * A batch, but only for the person who generated it. Batches hold unsaved
     * images, so one user must not be able to read or act on another's.
     *
     * @return array<string, mixed>
     */
    protected function ownedBatch(BatchStore $store, string $id): array
    {
        abort_unless($batch = $store->find($id), 404);
        abort_unless(($batch['user'] ?? null) === (string) User::current()->id(), 403);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    protected function ownedItem(array $batch, int $index): array
    {
        foreach ($batch['items'] as $item) {
            if ($item['index'] === $index) {
                return $item;
            }
        }

        abort(404);
    }

    protected function refuse(string $code, string $message, int $status = 409): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
