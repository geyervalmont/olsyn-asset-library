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
            <x-ui.panel><h2>{{ __('Install OPAL Drive') }}</h2>
                <p>{{ __('OPAL Drive mounts an account-scoped Windows drive using HTTPS. Install OPAL-Drive-Setup.exe as an administrator; it includes the upstream-signed Dokany filesystem driver and a self-contained Windows x64 app. Restart if requested by the driver installer.') }}</p>
                <p>{{ __('For managed deployment use /VERYSILENT /SUPPRESSMSGBOXES /NORESTART. The app installs under Program Files and starts in each user’s Windows session at sign-in. The user approves their account in the browser. Run the tray app without elevation; choose the same free drive letter across the team. No public SMB ports or VPN are required.') }}</p>
                <p>{{ __('The drive uses Windows proxy settings, current-user proxy authentication and the system certificate store. Validate PAC, TLS inspection and endpoint-security policies on your managed image. New file opens check access online; revoked or unavailable accounts cannot continue reading through the adapter. Previously downloaded or copied files remain in the user profile.') }}</p>
                <p>{{ __('The OPAL installer signing status and SHA-256 are recorded in each release manifest. Uninstall leaves the shared Dokany driver and user caches/upload staging in place. Closing the window keeps the drive running; Quit and unmount stops it.') }}</p>
            </x-ui.panel>
            <x-ui.panel><h2>{{ __('Accounts and support') }}</h2>
                <p>{{ __('Each person signs in with their own account. No shared drive token or object-storage credentials should be distributed to designers.') }}</p>
                <p>{{ __('For troubleshooting, record the computer name, Revit version, connector version and the error shown in OPAL Settings. Never include passwords or access tokens.') }}</p>
            </x-ui.panel>
        </div>
    </section>
</x-layouts::app>
