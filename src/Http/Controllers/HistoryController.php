<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\History\SavedImages;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class HistoryController extends CpController
{
    public function index(Request $request, SavedImages $history)
    {
        return response()->json($history->page(
            User::current(),
            (int) $request->query('page', 1),
            mb_substr((string) $request->query('search', ''), 0, 200),
            $request->filled('per_page') ? (int) $request->query('per_page') : null,
        ));
    }
}
