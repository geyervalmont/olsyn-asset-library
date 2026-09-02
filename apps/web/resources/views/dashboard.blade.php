@php
    $user = auth()->user();
    $currentTenant = $user->currentTenant;
    $currentTenant = $currentTenant !== null && $user->canAccessTenant($currentTenant) ? $currentTenant : null;
    $memberships = $user->tenants()->withCount('users')->orderBy('name')->get();
    $allTenants = $user->isSuperAdmin() ? \App\Models\Tenant::query()->withCount('users')->orderBy('name')->get() : collect();
@endphp

<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        @if (session('status'))
            <flux:callout variant="secondary" icon="information-circle" :heading="session('status')" data-test="dashboard-status" />
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Welcome, :name', ['name' => $user->name]) }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($currentTenant)
                        {{ __('You are working in :tenant.', ['tenant' => $currentTenant->name]) }}
                    @else
                        {{ __('You are not in a workspace yet.') }}
                    @endif
                </flux:text>
            </div>

            @if ($user->isSuperAdmin())
                <flux:badge color="amber" icon="shield-check" data-test="super-admin-badge">{{ __('Super-admin') }}</flux:badge>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading size="lg">{{ __('Current workspace') }}</flux:heading>

                @if ($currentTenant)
                    @php $role = $user->roleIn($currentTenant); @endphp
                    <dl class="mt-4 grid gap-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500">{{ __('Name') }}</dt>
                            <dd class="font-medium" data-test="current-workspace">{{ $currentTenant->name }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500">{{ __('Your role') }}</dt>
                            <dd class="font-medium">{{ $role?->label() ?? ($user->isSuperAdmin() ? __('Super-admin') : __('None')) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-500">{{ __('Members') }}</dt>
                            <dd class="font-medium">{{ $currentTenant->users()->count() }}</dd>
                        </div>
                    </dl>
                @else
                    <flux:text class="mt-3" data-test="no-workspace">
                        {{ __('Ask a workspace admin to add you, or wait for a super-admin to create one for you. You can still manage your account settings.') }}
                    </flux:text>
                @endif
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading size="lg">{{ __('Your workspaces') }}</flux:heading>

                @if ($memberships->isEmpty())
                    <flux:text class="mt-3">{{ __('No memberships.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($memberships as $tenant)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div>
                                    <div class="text-sm font-medium">{{ $tenant->name }}</div>
                                    <div class="text-xs text-zinc-500">
                                        {{ $user->roleIn($tenant)?->label() ?? __('Member') }}
                                        · {{ trans_choice(':count member|:count members', $tenant->users_count) }}
                                    </div>
                                </div>
                                @if ($tenant->is($currentTenant))
                                    <flux:badge size="sm" color="lime">{{ __('Current') }}</flux:badge>
                                @else
                                    <form method="POST" action="{{ route('tenants.switch', $tenant) }}">
                                        @csrf
                                        <flux:button size="sm" type="submit">{{ __('Switch') }}</flux:button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @if ($user->isSuperAdmin())
            <div class="rounded-xl border border-amber-200 p-5 dark:border-amber-900/60" data-test="all-workspaces">
                <flux:heading size="lg">{{ __('All workspaces') }}</flux:heading>
                <flux:text class="mt-1">{{ __('As a super-admin you can enter any workspace without being a member.') }}</flux:text>

                @if ($allTenants->isEmpty())
                    <flux:text class="mt-3">{{ __('No workspaces exist yet.') }}</flux:text>
                @else
                    <ul class="mt-4 divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($allTenants as $tenant)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div>
                                    <div class="text-sm font-medium">{{ $tenant->name }}</div>
                                    <div class="text-xs text-zinc-500">
                                        <code>{{ $tenant->slug }}</code>
                                        · {{ trans_choice(':count member|:count members', $tenant->users_count) }}
                                    </div>
                                </div>
                                @if ($tenant->is($currentTenant))
                                    <flux:badge size="sm" color="lime">{{ __('Current') }}</flux:badge>
                                @else
                                    <form method="POST" action="{{ route('tenants.switch', $tenant) }}">
                                        @csrf
                                        <flux:button size="sm" type="submit">{{ __('Enter') }}</flux:button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
</x-layouts::app>
