<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Trash;
use D3Creative\Darkroom\History\Usages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Moving History images to the trash and back, and deleting them for good.
 *
 * Every action takes a list of asset ids, since images are chosen with
 * checkboxes. Each one is checked on its own, and the answer says which were
 * done and which were refused, so one image someone may not touch does not
 * stop the rest.
 *
 * Moving to the trash needs permission to delete the asset, because that is
 * where the trash leads. Restoring or keeping an image only changes its
 * Darkroom data, so permission to edit it is enough.
 */
class TrashController extends CpController
{
    public function index(Trash $trash)
    {
        return response()->json(['items' => $trash->list(User::current())]);
    }

    /**
     * Where each image is used on the site, asked before moving or deleting.
     */
    public function usages(Request $request, Usages $usages)
    {
        $assets = $this->assets($request, fn (AssetContract $asset) => $this->allows('view', $asset));

        return response()->json(['usages' => $usages->find($assets['allowed'], User::current())]);
    }

    public function move(Request $request, Trash $trash)
    {
        return $this->each($request, 'delete', fn (AssetContract $asset) => Trash::isTrashed($asset) ?: $trash->move($asset, User::current()));
    }

    public function restore(Request $request, Trash $trash)
    {
        return $this->each($request, 'edit', fn (AssetContract $asset) => $trash->restore($asset));
    }

    public function forget(Request $request, Trash $trash)
    {
        return $this->each($request, 'edit', fn (AssetContract $asset) => $trash->forget($asset));
    }

    /**
     * Delete for good. Only from the trash, so nothing in History can be
     * deleted in one step.
     */
    public function destroy(Request $request, Trash $trash)
    {
        return $this->each($request, 'delete', fn (AssetContract $asset) => $trash->destroy($asset), trashedOnly: true);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function each(Request $request, string $ability, callable $action, bool $trashedOnly = false)
    {
        $assets = $this->assets($request, fn (AssetContract $asset) => $this->allows($ability, $asset)
            && (! $trashedOnly || Trash::isTrashed($asset)));

        foreach ($assets['allowed'] as $asset) {
            $action($asset);
        }

        return response()->json([
            'done' => array_map(fn (AssetContract $asset) => $asset->id(), $assets['allowed']),
            'refused' => $assets['refused'],
        ]);
    }

    /**
     * The Darkroom assets named in the request, split into those this user
     * may act on and those they may not. An asset Darkroom did not save is
     * always refused: these endpoints are not a way to delete anything else.
     *
     * @return array{allowed: array<int, AssetContract>, refused: array<int, string>}
     */
    protected function assets(Request $request, callable $allowed): array
    {
        $ids = $request->validate([
            'assets' => ['required', 'array', 'max:200'],
            'assets.*' => ['string', 'max:500'],
        ])['assets'];

        $result = ['allowed' => [], 'refused' => []];

        foreach (array_unique($ids) as $id) {
            $asset = Asset::find($id);

            if ($asset && $asset->get(SavedImages::KEY) && $allowed($asset)) {
                $result['allowed'][] = $asset;
            } else {
                $result['refused'][] = $id;
            }
        }

        return $result;
    }

    protected function allows(string $ability, AssetContract $asset): bool
    {
        return Gate::forUser(User::current())->allows($ability, $asset);
    }
}
