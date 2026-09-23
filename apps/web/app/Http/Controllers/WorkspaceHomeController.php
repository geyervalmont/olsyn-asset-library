<?php

namespace App\Http\Controllers;

use App\Support\SharedWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceHomeController
{
    public function __invoke(Request $request, SharedWorkspace $workspace): View|RedirectResponse
    {
        if (! config('opal.workspace.single')) {
            return view('dashboard');
        }
        $tenant = $request->user()->resolveCurrentTenant();
        if ($tenant !== null) {
            return redirect()->route('materials.index');
        }

        return view('workspace-welcome', ['workspace' => $workspace->tenant()]);
    }
}
