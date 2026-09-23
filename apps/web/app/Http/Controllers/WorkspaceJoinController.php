<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceJoinController
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('connect');
        }
        if (config('olsyn_access.enabled')) {
            return redirect()->route('olsyn.login', ['return_to' => '/connect']);
        }
        $request->session()->put('url.intended', route('connect'));

        return redirect()->route('login');
    }
}
