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
        ['route' => 'materials.index', 'match' => 'materials.index|materials.show', 'label' => __('Materials'), 'icon' => 'materials', 'show' => $shellUser?->can('materials.view')],
        ['route' => 'materials.studio', 'match' => 'materials.studio*', 'label' => __('Material Studio'), 'icon' => 'studio', 'show' => $shellUser?->can('materials.contribute')],
        ['route' => 'ingestion.index', 'match' => 'ingestion.*|materials.create', 'label' => __('Ingestion'), 'icon' => 'import', 'show' => $shellUser?->can('materials.contribute')],
        ['route' => 'connect', 'match' => 'connect|connect.it|link|revit.edit|sessions.edit', 'label' => __('Apps & drive'), 'icon' => 'connect', 'show' => true],
    ], fn (array $item): bool => (bool) $item['show']));
    $management = array_values(array_filter([
        ['route' => 'jobs.index', 'match' => 'jobs.*', 'label' => __('Processing jobs'), 'icon' => 'jobs', 'show' => $shellUser?->can('materials.contribute')],
        ['route' => 'quality.index', 'match' => 'quality.*', 'label' => __('Quality review'), 'icon' => 'quality', 'show' => $shellUser?->can('materials.review')],
        ['route' => 'drives.index', 'match' => 'drives.*', 'label' => __('Drives'), 'icon' => 'drive', 'show' => $shellUser?->can('materials.publish')],
        ['route' => 'connect.health', 'match' => 'connect.health', 'label' => __('Drive health'), 'icon' => 'health', 'show' => $shellUser?->isSuperAdmin()],
        ['route' => 'workspace.team', 'match' => 'workspace.team', 'label' => __('Team'), 'icon' => 'team', 'show' => $singleWorkspace && $shellUser?->can('members.manage')],
    ], fn (array $item): bool => (bool) $item['show']));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="color-scheme" content="light" />
    </head>
    <body class="ui-page ui-page--app" data-ui x-data="appNavigation" x-on:keydown.escape.window="navigationOpen = false" x-on:livewire:navigating.document="navigationOpen = false" x-on:resize.window.debounce.150ms="if (window.innerWidth > 820) navigationOpen = false">
        <a class="ui-skip" href="#app-content">{{ __('Skip to content') }}</a>

        <div class="ui-shell ui-shell--app" x-bind:class="sidebarCollapsed && 'is-sidebar-collapsed'">
            <aside class="ui-sidebar ui-app-sidebar" id="app-sidebar" :class="navigationOpen && 'is-open'" x-trap.inert.noscroll="navigationOpen" aria-label="{{ __('Sidebar') }}" x-on:click="if ($event.target.closest('a')) navigationOpen = false">
                <div class="ui-app-sidebar__head">
                    <a class="ui-app-brand" href="{{ route('materials.index') }}" aria-label="{{ __('OPAL material library') }}" wire:navigate>
                        <span class="ui-app-brand__mark" aria-hidden="true">
                            <svg viewBox="0 0 32 32" fill="none"><path d="M5.5 6.5h13v13h-13zM13.5 12.5h13v13h-13z"/><path d="M20 6.5h6.5V13H20z"/></svg>
                        </span>
                        <span class="ui-app-brand__name"><strong>OPAL</strong><small>{{ __('by Olsyn') }}</small></span>
                    </a>
                    <button class="ui-sidebar__close" type="button" x-on:click="navigationOpen = false" aria-label="{{ __('Close navigation') }}"><span aria-hidden="true">×</span></button>
                </div>

                @if ($singleWorkspace)
                    <div class="ui-app-workspace" data-test="shared-library" title="{{ $shellTenant?->name ?? 'OPAL' }}">
                        <span class="ui-app-workspace__avatar" aria-hidden="true">{{ mb_substr($shellTenant?->name ?? 'O', 0, 1) }}</span>
                        <span class="ui-app-workspace__name"><strong>{{ $shellTenant?->name ?? 'OPAL' }}</strong><small>{{ __('Shared workspace') }}</small></span>
                    </div>
                @else
                    <details class="ui-app-workspace-menu ui-menu" data-test="workspace-switcher" x-on:click.outside="$el.removeAttribute('open')" x-on:keydown.escape.stop="$el.removeAttribute('open')">
                        <summary class="ui-app-workspace" aria-label="{{ __('Switch workspace') }}">
                            <span class="ui-app-workspace__avatar" aria-hidden="true">{{ mb_substr($shellTenant?->name ?? 'O', 0, 1) }}</span>
                            <span class="ui-app-workspace__name"><strong>{{ $shellTenant?->name ?? __('No workspace') }}</strong><small>{{ __('Workspace') }}</small></span>
                            <x-ui.nav-icon name="chevron" class="ui-app-workspace__chevron" />
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

                <div class="ui-app-sidebar__scroll">
                    <nav class="ui-app-nav" aria-label="{{ __('Primary') }}">
                        <p class="ui-app-nav__heading">{{ __('Library') }}</p>
                        @foreach ($navigation as $item)
                            <x-ui.nav-link :href="route($item['route'])" :label="$item['label']" :icon="$item['icon']" :active="request()->routeIs(...explode('|', $item['match']))" />
                        @endforeach
                    </nav>
                    @if ($management !== [])
                        <nav class="ui-app-nav ui-app-nav--manage" aria-label="{{ __('Library management') }}">
                            <p class="ui-app-nav__heading">{{ __('Manage') }}</p>
                            @foreach ($management as $item)
                                <x-ui.nav-link :href="route($item['route'])" :label="$item['label']" :icon="$item['icon']" :active="request()->routeIs($item['match'])" />
                            @endforeach
                        </nav>
                    @endif
                </div>

                <div class="ui-app-sidebar__foot">
                    <nav class="ui-app-nav ui-app-nav--account" aria-label="{{ __('Account') }}">
                        @unless($singleWorkspace)
                            <x-ui.nav-link :href="route('dashboard')" :label="__('Workspaces')" icon="workspaces" :active="request()->routeIs('dashboard')" />
                        @endunless
                        <x-ui.nav-link :href="route('profile.edit')" :label="__('Settings')" icon="settings" :active="request()->routeIs('profile.edit', 'security.edit', 'api-tokens.edit')" />
                        @if (Route::has('ui.index'))
                            <x-ui.nav-link :href="route('ui.index')" :label="__('UI workbench')" icon="code" :active="request()->routeIs('ui.index')" />
                        @endif
                        <button class="ui-app-nav__link ui-sidebar-toggle" type="button" x-on:click="toggleSidebar()" x-bind:aria-label="sidebarCollapsed ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))" x-bind:title="sidebarCollapsed ? @js(__('Expand sidebar')) : null" x-bind:aria-expanded="! sidebarCollapsed" aria-controls="app-sidebar" data-test="sidebar-toggle">
                            <x-ui.nav-icon name="sidebar" />
                            <span class="ui-app-nav__text">{{ __('Collapse sidebar') }}</span>
                        </button>
                    </nav>

                    <details class="ui-app-user ui-menu" data-test="user-menu" x-on:click.outside="$el.removeAttribute('open')" x-on:keydown.escape.stop="$el.removeAttribute('open')">
                        <summary aria-label="{{ __('Account menu') }}" x-bind:title="sidebarCollapsed ? @js($shellUser?->name) : null">
                            <span class="ui-avatar" aria-hidden="true">{{ $shellUser?->initials() }}</span>
                            <span class="ui-app-user__identity"><strong>{{ $shellUser?->name }}</strong><small>{{ $shellUser?->email }}</small></span>
                            <x-ui.nav-icon name="chevron" class="ui-app-user__chevron" />
                        </summary>
                        <div class="ui-menu__content">
                            <div class="ui-app-user__menu-head"><strong>{{ $shellUser?->name }}</strong><small>{{ $shellUser?->email }}</small></div>
                            <a href="{{ route('profile.edit') }}" wire:navigate><x-ui.nav-icon name="settings" />{{ __('Account settings') }}</a>
                            @if (! $singleWorkspace && $shellUser?->isSuperAdmin())
                                <a href="{{ route('dashboard') }}" wire:navigate><x-ui.nav-icon name="workspaces" />{{ __('All workspaces') }}</a>
                            @endif
                            <hr>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" data-test="logout-button"><x-ui.nav-icon name="logout" />{{ __('Log out') }}</button>
                            </form>
                        </div>
                    </details>
                </div>
            </aside>

            <button class="ui-sidebar-scrim" type="button" x-show="navigationOpen" x-cloak x-on:click="navigationOpen = false" aria-label="{{ __('Close navigation') }}"></button>

            <div class="ui-workspace">
                <header class="ui-topbar">
                    <button class="ui-menu-button" type="button" x-on:click="navigationOpen = true" aria-label="{{ __('Open navigation') }}" aria-controls="app-sidebar" x-bind:aria-expanded="navigationOpen">
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
