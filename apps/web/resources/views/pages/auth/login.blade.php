<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="config('olsyn_access.enabled') ? __('Welcome to OPAL') : __('Log in to your account')" :description="config('olsyn_access.enabled') ? __('Sign in with Olsyn to open your shared material library.') : __('Enter your email and password below to log in')" />

        @php
            $intended = session('url.intended');
            $pendingCode = null;
            if (is_string($intended) && parse_url($intended, PHP_URL_PATH) === '/link') {
                parse_str((string) parse_url($intended, PHP_URL_QUERY), $pendingQuery);
                $pendingCode = $pendingQuery['code'] ?? null;
            }
        @endphp
        @if (is_string($pendingCode))
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-950" data-test="pending-device-link">
                <strong class="block">Revit is waiting</strong>
                <span>After signing in, you will return to connection code <code>{{ $pendingCode }}</code> to approve it.</span>
            </div>
        @endif

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (config('olsyn_access.enabled'))
            <flux:button :href="route('olsyn.login')" variant="primary" class="w-full">Continue with Olsyn</flux:button>
            <p class="text-sm text-center text-zinc-500">{{ __('Need access? Ask your administrator to enable OPAL for your Olsyn account.') }}</p>
            <details class="rounded-lg border border-zinc-200 p-4" @if($errors->any()) open @endif>
                <summary class="cursor-pointer text-sm">{{ __('Use an existing OPAL password or passkey') }}</summary>
                <div class="mt-5 flex flex-col gap-6">
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        @if(config('olsyn_access.enabled'))
                </div>
            </details>
        @else
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
        @endif
    </div>
</x-layouts::auth>
