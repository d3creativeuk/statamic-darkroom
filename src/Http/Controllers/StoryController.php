<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\Revisions\RevisionHistory;
use D3Creative\Darkroom\Revisions\Story;
use D3Creative\Darkroom\Revisions\ThreadAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * A saved revised image's story, and deleting it.
 */
class StoryController extends CpController
{
    public function show(Request $request, Story $story)
    {
        $asset = $this->asset($request);

        abort_unless($found = $story->of($asset, User::current()), 404);

        return response()->json($found);
    }

    /**
     * Delete an image's revision history: the steps kept for it, unless
     * another saved image's story or a page still uses them, and the story
     * itself. The image stays.
     */
    public function forget(Request $request, RevisionHistory $history)
    {
        $asset = $this->asset($request);
        $user = User::current();

        abort_unless(Gate::forUser($user)->allows('edit', $asset), 403);

        $stamp = (array) $asset->get(SavedImages::KEY);
        $thread = $stamp['thread']['id'] ?? null;
        $round = ThreadAssets::savedRound($stamp);

        abort_unless(is_string($thread) && $round, 404);

        // Any later image's story that shows this one keeps a copy of it,
        // since without its history it can no longer be found as a round.
        $history->preserve($asset, asOriginal: false);

        $result = $history->release($thread, $round, $user);

        unset($stamp['thread'], $stamp['revision']);
        $asset->set(SavedImages::KEY, $stamp)->save();

        return response()->json($result);
    }

    protected function asset(Request $request): AssetContract
    {
        $asset = Asset::find((string) $request->input('asset'));

        abort_unless($asset, 404);
        abort_unless(Gate::forUser(User::current())->allows('view', $asset), 403);

        return $asset;
    }
}
