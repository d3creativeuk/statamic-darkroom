<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\History\Favourites;
use D3Creative\Darkroom\History\SavedImages;
use D3Creative\Darkroom\History\Trash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Hearting an image in History, or taking the heart away.
 *
 * A favourite is the user's own mark and changes nothing anyone else sees,
 * so being able to view the image is enough. Only images listed in History
 * can be hearted: anything Darkroom did not save, or that is in the trash,
 * reads as not found.
 */
class FavouriteController extends CpController
{
    public function update(Request $request, Favourites $favourites)
    {
        $data = $request->validate([
            'asset' => ['required', 'string', 'max:500'],
            'favourite' => ['required', 'boolean'],
        ]);

        $user = User::current();
        $asset = Asset::find($data['asset']);

        if (! $asset || ! $asset->get(SavedImages::KEY) || Trash::isTrashed($asset) || ! Gate::forUser($user)->allows('view', $asset)) {
            abort(404);
        }

        $favourites->set($asset, $user, (bool) $data['favourite']);

        return response()->json(['favourite' => Favourites::has($asset, $user)]);
    }
}
