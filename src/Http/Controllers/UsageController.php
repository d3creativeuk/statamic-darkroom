<?php

namespace D3Creative\Darkroom\Http\Controllers;

use D3Creative\Darkroom\Usage\UsageLog;
use Statamic\Http\Controllers\CP\CpController;

class UsageController extends CpController
{
    public function index(UsageLog $usage)
    {
        return response()->json(['months' => $usage->months()]);
    }

    public function show(string $month, UsageLog $usage)
    {
        return response()->json(['month' => $month, 'entries' => $usage->entries($month)]);
    }
}
