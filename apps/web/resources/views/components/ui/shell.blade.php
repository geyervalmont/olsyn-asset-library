@props([
    'title' => 'UI workbench',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="color-scheme" content="light" />
        <title>{{ $title }} - {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="ui-page" data-ui x-data="{ navigationOpen: false }">
        <div class="ui-grain" aria-hidden="true"></div>
        <a class="ui-skip" href="#ui-content">Skip to content</a>

        <div class="ui-shell">
            <aside class="ui-sidebar" :class="navigationOpen && 'is-open'">
                <div class="ui-sidebar__top">
                    <x-ui.brand href="{{ route('ui.index') }}" />
                    <button class="ui-sidebar__close" type="button" x-on:click="navigationOpen = false" aria-label="Close navigation">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>

                <nav class="ui-sidebar__nav" aria-label="Workbench sections">
                    <p>Workbench</p>
                    <a href="#overview"><span>00</span>Overview</a>
                    <a href="#foundations"><span>01</span>Foundations</a>
                    <a href="#components"><span>02</span>Components</a>
                    <a href="#data"><span>03</span>Data patterns</a>
                    <a href="#templates"><span>04</span>Templates</a>
                </nav>

                <div class="ui-sidebar__note">
                    <span class="ui-status-light" aria-hidden="true"></span>
                    <div>
                        <strong>Local workbench</strong>
                        <small>Blade · Livewire · Flux</small>
                    </div>
                </div>
            </aside>

            <button class="ui-sidebar-scrim" type="button" x-show="navigationOpen" x-cloak x-on:click="navigationOpen = false" aria-label="Close navigation"></button>

            <div class="ui-workspace">
                <header class="ui-topbar">
                    <button class="ui-menu-button" type="button" x-on:click="navigationOpen = true" aria-label="Open navigation">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <div class="ui-breadcrumb">
                        <span>Olsyn</span><i>/</i><strong>UI workbench</strong>
                    </div>
                    <div class="ui-topbar__actions">
                        <x-ui.badge tone="info" dot>UI study</x-ui.badge>
                        <x-ui.button href="{{ route('dashboard') }}" variant="quiet" size="sm">Open app</x-ui.button>
                    </div>
                </header>

                <main id="ui-content" class="ui-content">
                    {{ $slot }}

                    <footer class="ui-footer">
                        <x-ui.brand />
                        <p>Internal interface study · Paper & Periwinkle / 0.1</p>
                    </footer>
                </main>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
