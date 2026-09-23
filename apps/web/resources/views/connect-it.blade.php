<x-layouts::app :title="__('IT setup notes')">
    <section class="ui-narrow">
        <x-ui.button href="{{ route('connect') }}" variant="quiet" size="sm" wire:navigate>← {{ __('Back to Connect') }}</x-ui.button>
        <div class="ui-page-head"><div><x-ui.eyebrow>{{ __('Deployment guide') }}</x-ui.eyebrow><h1>{{ __('OPAL on managed computers') }}</h1><p class="ui-page-head__lede">{{ __('Share this page with the team that manages your Windows applications.') }}</p></div></div>
        <div class="ui-stack">
            <x-ui.panel><h2>{{ __('Install the Revit connector') }}</h2>
                <p>{{ __('The current installer supports Revit 2024–2027 and installs for the signed-in Windows user. Close Revit before installation. Revit LT cannot load add-ins.') }}</p>
                <p>{{ __('The installer accepts /VERYSILENT /SUPPRESSMSGBOXES /NORESTART for managed deployment. Run in the intended user’s context, then have that user sign in from Revit → OPAL → Settings.') }}</p>
                <x-ui.button href="{{ route('revit.download', ['asset' => 'installer']) }}" variant="secondary">{{ __('Download current installer') }}</x-ui.button>
            </x-ui.panel>
            <x-ui.panel><h2>{{ __('Network access') }}</h2>
                <ul class="ui-list">
                    <li><strong>opal.olsyn.com · TCP 443</strong><span>{{ __('Library, account linking and API') }}</span></li>
                    <li><strong>opal-ws.olsyn.com · TCP 443</strong><span>{{ __('Secure WebSocket connection for Revit commands') }}</span></li>
                    <li><strong>github.com / release-assets.githubusercontent.com · TCP 443</strong><span>{{ __('Current installer and update downloads redirect to GitHub') }}</span></li>
                </ul>
                <p>{{ __('Browser sign-in also follows your organisation’s identity provider. Permit the identity domains used by your OPAL account.') }}</p>
                <p>{{ __('Validate the connector under your proxy and certificate-inspection policy. OPAL does not bypass certificate validation.') }}</p>
            </x-ui.panel>
            <x-ui.panel><h2>{{ __('Material-drive availability') }}</h2>
                <p>{{ __('The current Revit connector uses a configured local or network material path. The production SMB service is private inside the cluster and is not automatically reachable from workstations.') }}</p>
                <p>{{ __('The Windows virtual drive over HTTPS is not included in the current installer. Its account-scoped file API and upload staging are being prepared separately. Do not open public SMB ports to connect to OPAL.') }}</p>
            </x-ui.panel>
            <x-ui.panel><h2>{{ __('Accounts and support') }}</h2>
                <p>{{ __('Each person signs in with their own account. No shared drive token or object-storage credentials should be distributed to designers.') }}</p>
                <p>{{ __('For troubleshooting, record the computer name, Revit version, connector version and the error shown in OPAL Settings. Never include passwords or access tokens.') }}</p>
            </x-ui.panel>
        </div>
    </section>
</x-layouts::app>
