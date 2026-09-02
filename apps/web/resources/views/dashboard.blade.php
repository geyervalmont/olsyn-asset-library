@php
    $user = auth()->user();
    $currentTenant = $user->currentTenant;
    $currentTenant = $currentTenant !== null && $user->canAccessTenant($currentTenant) ? $currentTenant : null;
    $memberships = $user->tenants()->withCount('users')->orderBy('name')->get();
    $allTenants = $user->isSuperAdmin() ? \App\Models\Tenant::query()->withCount('users')->orderBy('name')->get() : collect();
@endphp

<x-layouts::app :title="__('Workspaces')">
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Account') }}</x-ui.eyebrow>
            <h1>{{ __('Workspaces') }}</h1>
            <p class="ui-page-head__lede">
                @if ($currentTenant)
                    {{ __('You are working in :tenant. The library itself is shared; a workspace decides what you may do in it.', ['tenant' => $currentTenant->name]) }}
                @else
                    {{ __('You are not in a workspace yet.') }}
                @endif
            </p>
        </div>
    </div>

    <div class="ui-grid-2">
        <x-ui.panel>
            <div class="ui-panel__heading"><div><h3>{{ __('Current workspace') }}</h3></div></div>
            @if ($currentTenant)
                @php $role = $user->roleIn($currentTenant); @endphp
                <ul class="ui-list">
                    <li><small>{{ __('Name') }}</small><strong data-test="current-workspace">{{ $currentTenant->name }}</strong></li>
                    <li><small>{{ __('Your role') }}</small><strong>{{ $role?->label() ?? ($user->isSuperAdmin() ? __('Super-admin') : __('None')) }}</strong></li>
                    <li><small>{{ __('Members') }}</small><strong>{{ $currentTenant->users()->count() }}</strong></li>
                </ul>
            @else
                <p class="ui-variant__attrs" data-test="no-workspace">{{ __('Ask a workspace admin to add you, or wait for a super-admin to create one for you. You can still manage your account settings.') }}</p>
            @endif
        </x-ui.panel>

        <x-ui.panel>
            <div class="ui-panel__heading"><div><h3>{{ __('Your workspaces') }}</h3></div></div>
            @if ($memberships->isEmpty())
                <p class="ui-variant__attrs">{{ __('No memberships.') }}</p>
            @else
                <ul class="ui-list">
                    @foreach ($memberships as $tenant)
                        <li>
                            <span><strong>{{ $tenant->name }}</strong> <small>· {{ $user->roleIn($tenant)?->label() ?? __('Member') }} · {{ trans_choice(':count member|:count members', $tenant->users_count) }}</small></span>
                            @if ($tenant->is($currentTenant))
                                <x-ui.badge tone="success" dot>{{ __('Current') }}</x-ui.badge>
                            @else
                                <form method="POST" action="{{ route('tenants.switch', $tenant) }}">@csrf<x-ui.button type="submit" variant="quiet" size="sm">{{ __('Switch') }}</x-ui.button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>

    @if ($user->isSuperAdmin())
        <x-ui.panel tone="amber" style="margin-top: 12px" data-test="all-workspaces">
            <div class="ui-panel__heading"><div><h3>{{ __('All workspaces') }}</h3><p>{{ __('As a super-admin you can enter any workspace without being a member.') }}</p></div></div>
            @if ($allTenants->isEmpty())
                <p class="ui-variant__attrs">{{ __('No workspaces exist yet.') }}</p>
            @else
                <ul class="ui-list">
                    @foreach ($allTenants as $tenant)
                        <li>
                            <span><strong>{{ $tenant->name }}</strong> <small>· {{ $tenant->slug }} · {{ trans_choice(':count member|:count members', $tenant->users_count) }}</small></span>
                            @if ($tenant->is($currentTenant))
                                <x-ui.badge tone="success" dot>{{ __('Current') }}</x-ui.badge>
                            @else
                                <form method="POST" action="{{ route('tenants.switch', $tenant) }}">@csrf<x-ui.button type="submit" variant="quiet" size="sm">{{ __('Enter') }}</x-ui.button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    @endif
</x-layouts::app>
