<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <meta name="color-scheme" content="light" />
    </head>
    <body class="ui-page" data-ui>
        <div class="ui-grain" aria-hidden="true"></div>
        <div class="ui-auth">
            <div class="ui-auth__card">
                <div class="ui-auth__brand">
                    <x-ui.brand href="{{ route('home') }}" />
                </div>
                <x-ui.panel :padding="false">
                    {{ $slot }}
                </x-ui.panel>
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
