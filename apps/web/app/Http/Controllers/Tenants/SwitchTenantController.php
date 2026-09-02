<?php

namespace App\Http\Controllers\Tenants;

use App\Actions\Tenants\SwitchCurrentTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchTenantController
{
    public function __invoke(Request $request, Tenant $tenant, SwitchCurrentTenant $switch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $switch->handle($user, $tenant, $request);
        Tenant::forgetCurrent();

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', __('Switched to :tenant.', ['tenant' => $tenant->name]));
    }
}
