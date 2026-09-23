<x-layouts::app :title="__('Welcome')">
    <section class="ui-narrow">
        <div class="ui-page-head"><div><x-ui.eyebrow>{{ __('Welcome to OPAL') }}</x-ui.eyebrow><h1>{{ __('Let’s get you connected.') }}</h1></div></div>
        <x-ui.panel>
            <h2>{{ $workspace ? __('Your team access is being set up') : __('The shared library is being set up') }}</h2>
            <p>{{ __('You’re signed in as :email.', ['email' => auth()->user()->email]) }}</p>
            <p>{{ __('Ask your administrator to add this email address on the OPAL Team page. If you were invited using another email, sign in with that account.') }}</p>
            @if(config('olsyn_access.enabled'))<p>{{ __('Your account also needs OPAL access in Olsyn. Once approved, there is no workspace to choose or create.') }}</p>@endif
            <div class="ui-actions" style="margin-top: 20px">
                <x-ui.button href="{{ route('dashboard') }}">{{ __('Check access again') }}</x-ui.button>
                <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button type="submit" variant="secondary">{{ __('Use another account') }}</x-ui.button></form>
            </div>
        </x-ui.panel>
    </section>
</x-layouts::app>
