@props([
    'title' => null,
])

@php
    $shellUser = auth()->user();
    $singleWorkspace = config('opal.workspace.single');
    $shellTenant = $singleWorkspace ? \App\Models\Tenant::current() : $shellUser?->currentTenant;
    $shellTenant = $shellTenant !== null && $shellUser->canAccessTenant($shellTenant) ? $shellTenant : null;
    $shellTenants = $singleWorkspace ? collect() : ($shellUser?->accessibleTenants()->get() ?? collect());
    $navigation = array_values(array_filter([
        ['route' => 'materials.index', 'match' => 'materials.index|materials.show', 'label' => __('Library'), 'index' => '01', 'show' => $shellUser?->can('materials.view')],
        ['route' => 'materials.create', 'match' => 'materials.create|materials.studio*', 'label' => __('Create'), 'index' => '02', 'show' => $shellUser?->can('materials.contribute')],
        ['route' => 'connect', 'match' => 'connect|connect.it|link|revit.edit|sessions.edit', 'label' => __('Connect'), 'index' => '03', 'show' => true],
    ], fn (array $item): bool => (bool) $item['show']));
    $management = array_values(array_filter([
        ['route' => 'connect.health', 'match' => 'connect.health', 'label' => __('Drive health'), 'show' => $shellUser?->isSuperAdmin()],
        ['route' => 'quality.index', 'match' => 'quality.*', 'label' => __('Quality review'), 'show' => $shellUser?->can('materials.review')],
        ['route' => 'jobs.index', 'match' => 'jobs.*', 'label' => __('Processing jobs'), 'show' => $shellUser?->can('materials.contribute')],
        ['route' => 'drives.index', 'match' => 'drives.*', 'label' => __('Drive administration'), 'show' => $shellUser?->can('materials.publish')],
    ], fn (array $item): bool => (bool) $item['show']));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="color-scheme" content="light" />
    </head>
    <body class="ui-page ui-page--app" data-ui x-data="{ navigationOpen: false }">
        <a class="ui-skip" href="#app-content">{{ __('Skip to content') }}</a>

        <div class="ui-shell ui-shell--app">
            <aside class="ui-sidebar" :class="navigationOpen && 'is-open'">
                <div class="ui-sidebar__top">
                    <x-ui.brand href="{{ route('materials.index') }}" />
                    <button class="ui-sidebar__close" type="button" x-on:click="navigationOpen = false" aria-label="{{ __('Close navigation') }}">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>

                @if ($singleWorkspace)
                    <div class="ui-shared-library" data-test="shared-library"><small>{{ __('Shared library') }}</small><strong>{{ $shellTenant?->name ?? 'OPAL' }}</strong></div>
                @else
                <details class="ui-sidebar__workspace ui-menu" data-test="workspace-switcher">
                    <summary>
                        <span>
                            <small>{{ __('Workspace') }}</small>
                            {{ $shellTenant?->name ?? __('No workspace') }}
                        </span>
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4-4 4 4M6 12l4 4 4-4" /></svg>
                    </summary>
                    <div class="ui-menu__content">
                        @forelse ($shellTenants as $tenant)
                            <form method="POST" action="{{ route('tenants.switch', $tenant) }}">
                                @csrf
                                <button type="submit" @class(['is-current' => $tenant->is($shellTenant)])>{{ $tenant->name }}</button>
                            </form>
                        @empty
                            <button type="button" disabled>{{ __('No workspaces yet') }}</button>
                        @endforelse
                    </div>
                </details>

                @endif

                <nav class="ui-sidebar__nav" aria-label="{{ __('Primary') }}">
                    <p>{{ __('OPAL') }}</p>
                    @foreach ($navigation as $item)
                        <a href="{{ route($item['route']) }}" @class(['is-active' => request()->routeIs(...explode('|', $item['match']))]) wire:navigate>
                            <span aria-hidden="true"><svg viewBox="0 0 20 20">
                                @if ($item['index'] === '01')<rect x="3" y="3" width="5" height="5" rx="1"/><rect x="12" y="3" width="5" height="5" rx="1"/><rect x="3" y="12" width="5" height="5" rx="1"/><rect x="12" y="12" width="5" height="5" rx="1"/>
                                @elseif ($item['index'] === '02')<rect x="3" y="3" width="14" height="14" rx="3"/><path d="M6 10h8M10 6v8"/>
                                @else<rect x="2" y="3" width="16" height="11" rx="2"/><path d="M7 18h6M10 14v4"/>
                                @endif
                            </svg></span>{{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                @if ($management !== [])
                    <details class="ui-nav-management" @if(request()->routeIs('quality.*', 'jobs.*', 'drives.*')) open @endif>
                        <summary>{{ __('Manage library') }}</summary>
                        <nav class="ui-sidebar__nav" aria-label="{{ __('Library management') }}">
                            @foreach ($management as $item)
                                <a href="{{ route($item['route']) }}" @class(['is-active' => request()->routeIs($item['match'])]) wire:navigate>{{ $item['label'] }}</a>
                            @endforeach
                        </nav>
                    </details>
                @endif
                <nav class="ui-sidebar__nav ui-nav-account" aria-label="{{ __('Account') }}">
                    @if ($singleWorkspace)
                        @can('members.manage')
                            <a href="{{ route('workspace.team') }}" @class(['is-active' => request()->routeIs('workspace.team')]) wire:navigate>{{ __('Team') }}</a>
                        @endcan
                    @endif
                    @unless($singleWorkspace)<a href="{{ route('dashboard') }}" @class(['is-active' => request()->routeIs('dashboard')]) wire:navigate>{{ __('Workspaces') }}</a>@endunless
                    <a href="{{ route('profile.edit') }}" @class(['is-active' => request()->routeIs('profile.edit', 'security.edit', 'api-tokens.edit')]) wire:navigate>{{ __('Settings') }}</a>
                </nav>

                @if (Route::has('ui.index'))
                    <div class="ui-sidebar__section">
                        <nav class="ui-sidebar__nav" aria-label="{{ __('Development') }}" style="margin-top: 0">
                            <a href="{{ route('ui.index') }}"><span>··</span>{{ __('UI workbench') }}</a>
                        </nav>
                    </div>
                @endif

                <details class="ui-sidebar__user ui-menu" data-test="user-menu">
                    <summary>
                        <span class="ui-avatar" aria-hidden="true">{{ $shellUser?->initials() }}</span>
                        <span>
                            <strong>{{ $shellUser?->name }}</strong>
                            <small>{{ $shellUser?->email }}</small>
                        </span>
                    </summary>
                    <div class="ui-menu__content">
                        <a href="{{ route('profile.edit') }}" wire:navigate>{{ __('Settings') }}</a>
                        @if (! $singleWorkspace && $shellUser?->isSuperAdmin())
                            <a href="{{ route('dashboard') }}" wire:navigate>{{ __('All workspaces') }}</a>
                        @endif
                        <hr>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" data-test="logout-button">{{ __('Log out') }}</button>
                        </form>
                    </div>
                </details>
            </aside>

            <button class="ui-sidebar-scrim" type="button" x-show="navigationOpen" x-cloak x-on:click="navigationOpen = false" aria-label="{{ __('Close navigation') }}"></button>

            <div class="ui-workspace">
                <header class="ui-topbar">
                    <button class="ui-menu-button" type="button" x-on:click="navigationOpen = true" aria-label="{{ __('Open navigation') }}">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <div class="ui-breadcrumb">
                        <span>{{ $shellTenant?->name ?? 'OPAL' }}</span><i>/</i><strong>{{ $title ?? config('app.name') }}</strong>
                    </div>
                    <div class="ui-topbar__actions">
                        <x-ui.consumer-status />
                        @if ($shellUser?->isSuperAdmin())
                            <x-ui.badge tone="warning" dot data-test="super-admin-badge">{{ __('Super-admin') }}</x-ui.badge>
                        @endif
                        {{ $actions ?? '' }}
                    </div>
                </header>

                <main id="app-content" class="ui-content">
                    @if (session('status'))
                        <div class="ui-notice ui-flash" data-test="status">
                            <span class="ui-notice__rule" aria-hidden="true"></span>
                            <div><strong>{{ session('status') }}</strong></div>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
