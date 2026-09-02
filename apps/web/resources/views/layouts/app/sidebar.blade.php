<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $sidebarUser = auth()->user();
                $sidebarTenant = $sidebarUser->currentTenant;
                $sidebarTenant = $sidebarTenant !== null && $sidebarUser->canAccessTenant($sidebarTenant) ? $sidebarTenant : null;
                $sidebarTenants = $sidebarUser->accessibleTenants()->get();
            @endphp

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:dropdown position="bottom" align="start" class="w-full">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="building-office-2"
                            icon:trailing="chevrons-up-down"
                            class="w-full justify-start"
                            data-test="workspace-switcher"
                        >
                            {{ $sidebarTenant?->name ?? __('No workspace') }}
                        </flux:button>

                        <flux:menu>
                            @forelse ($sidebarTenants as $tenant)
                                <form method="POST" action="{{ route('tenants.switch', $tenant) }}" class="w-full">
                                    @csrf
                                    <flux:menu.item
                                        as="button"
                                        type="submit"
                                        :icon="$tenant->is($sidebarTenant) ? 'check' : 'building-office-2'"
                                        class="w-full cursor-pointer"
                                    >
                                        {{ $tenant->name }}
                                    </flux:menu.item>
                                </form>
                            @empty
                                <flux:menu.item disabled>{{ __('No workspaces yet') }}</flux:menu.item>
                            @endforelse
                        </flux:menu>
                    </flux:dropdown>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

            </flux:sidebar.nav>

            <flux:spacer />

            @if (Route::has('ui.index'))
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="paint-brush" :href="route('ui.index')">
                        {{ __('UI workbench') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
