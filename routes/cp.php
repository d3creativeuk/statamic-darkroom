<?php

use D3Creative\Darkroom\Http\Controllers\BatchController;
use D3Creative\Darkroom\Http\Controllers\DarkroomController;
use D3Creative\Darkroom\Http\Controllers\HistoryController;
use D3Creative\Darkroom\Http\Controllers\InstructionController;
use D3Creative\Darkroom\Http\Controllers\ItemController;
use D3Creative\Darkroom\Http\Controllers\PromptController;
use D3Creative\Darkroom\Http\Controllers\RevisionController;
use D3Creative\Darkroom\Http\Controllers\TrashController;
use D3Creative\Darkroom\Http\Controllers\UpscaleController;
use D3Creative\Darkroom\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// Each throttle names its own bucket. Without the third argument Laravel keys
// the limiter on the user alone, so every route here would draw from one
// shared counter and a page polling for status would use up the allowance
// for generating.
Route::middleware('can:use darkroom')->prefix('darkroom')->name('darkroom.')->group(function () {
    Route::get('/', [DarkroomController::class, 'index'])->name('index');

    Route::post('batches', [BatchController::class, 'store'])
        ->middleware('throttle:20,1,darkroom.generate')
        ->name('batches.store');

    Route::post('upscales', [UpscaleController::class, 'store'])
        ->middleware('throttle:20,1,darkroom.upscale')
        ->name('upscales.store');

    Route::post('revisions', [RevisionController::class, 'store'])
        ->middleware('throttle:20,1,darkroom.revise')
        ->name('revisions.store');

    Route::get('threads/{thread}', [RevisionController::class, 'thread'])
        ->where('thread', '[0-9a-z]{26}')
        ->middleware('throttle:60,1,darkroom.threads')
        ->name('threads.show');

    Route::get('assets/preview', [RevisionController::class, 'preview'])
        ->middleware('throttle:120,1,darkroom.asset-preview')
        ->name('assets.preview');

    Route::prefix('batches/{id}')->where(['id' => '[0-9a-z]{26}', 'index' => '[0-9]+'])->group(function () {
        Route::get('/', [BatchController::class, 'show'])
            ->middleware('throttle:240,1,darkroom.status')
            ->name('batches.show');

        Route::delete('/', [BatchController::class, 'destroy'])
            ->middleware('throttle:60,1,darkroom.discard-batch')
            ->name('batches.destroy');

        Route::get('items/{index}/preview', [ItemController::class, 'preview'])
            ->middleware('throttle:240,1,darkroom.preview')
            ->name('items.preview');

        Route::post('items/{index}/save', [ItemController::class, 'save'])
            ->middleware('throttle:60,1,darkroom.save')
            ->name('items.save');

        Route::post('items/{index}/alt', [ItemController::class, 'alt'])
            ->middleware('throttle:30,1,darkroom.alt')
            ->name('items.alt');

        Route::post('items/{index}/retry', [ItemController::class, 'retry'])
            ->middleware('throttle:20,1,darkroom.retry')
            ->name('items.retry');

        Route::delete('items/{index}', [ItemController::class, 'destroy'])
            ->middleware('throttle:60,1,darkroom.discard')
            ->name('items.destroy');
    });

    Route::get('folders/{container}', [DarkroomController::class, 'folders'])
        ->middleware('throttle:120,1,darkroom.folders')
        ->name('folders');

    Route::get('history', [HistoryController::class, 'index'])
        ->middleware('throttle:120,1,darkroom.history')
        ->name('history.index');

    Route::get('trash', [TrashController::class, 'index'])
        ->middleware('throttle:120,1,darkroom.trash')
        ->name('trash.index');

    Route::post('trash', [TrashController::class, 'move'])
        ->middleware('throttle:60,1,darkroom.trash.move')
        ->name('trash.move');

    Route::post('trash/restore', [TrashController::class, 'restore'])
        ->middleware('throttle:60,1,darkroom.trash.restore')
        ->name('trash.restore');

    Route::post('trash/destroy', [TrashController::class, 'destroy'])
        ->middleware('throttle:60,1,darkroom.trash.destroy')
        ->name('trash.destroy');

    // Reads all the site's content, so it is asked for once per action.
    Route::post('usages', [TrashController::class, 'usages'])
        ->middleware('throttle:30,1,darkroom.usages')
        ->name('usages');

    Route::post('history/forget', [TrashController::class, 'forget'])
        ->middleware('throttle:60,1,darkroom.forget')
        ->name('history.forget');

    Route::get('usage', [UsageController::class, 'index'])
        ->middleware('throttle:120,1,darkroom.usage')
        ->name('usage.index');

    Route::get('usage/{month}', [UsageController::class, 'show'])
        ->where('month', '\d{4}-(0[1-9]|1[0-2])')
        ->middleware('throttle:120,1,darkroom.usage.month')
        ->name('usage.show');

    Route::post('prompts', [PromptController::class, 'store'])
        ->middleware('throttle:60,1,darkroom.prompts.store')
        ->name('prompts.store');

    Route::patch('prompts/{id}', [PromptController::class, 'update'])
        ->middleware('throttle:60,1,darkroom.prompts.update')
        ->name('prompts.update');

    Route::delete('prompts/{id}', [PromptController::class, 'destroy'])
        ->middleware('throttle:60,1,darkroom.prompts.destroy')
        ->name('prompts.destroy');

    Route::post('instructions', [InstructionController::class, 'store'])
        ->middleware('throttle:60,1,darkroom.instructions.store')
        ->name('instructions.store');

    Route::patch('instructions/{id}', [InstructionController::class, 'update'])
        ->middleware('throttle:60,1,darkroom.instructions.update')
        ->name('instructions.update');

    Route::delete('instructions/{id}', [InstructionController::class, 'destroy'])
        ->middleware('throttle:60,1,darkroom.instructions.destroy')
        ->name('instructions.destroy');
});
