<?php

use App\Support\ClientReleaseCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Revit extension')] class extends Component {
    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function release(): ?array
    {
        try {
            return app(ClientReleaseCatalog::class)->latest(
                'revit',
                (string) config('opal.clients.revit.default_channel', 'development'),
            );
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return array<int, array{runtime: string, verification: string}>
     */
    #[Computed]
    public function supportedVersions(): array
    {
        return config('opal.clients.revit.supported_versions', []);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Revit extension')" :subheading="__('Connect Revit directly to OPAL. pyRevit and Python are not required.')">
        <div class="ui-stack" data-test="revit-download">
            <x-ui.panel tone="paper">
                <div class="ui-panel__heading">
                    <div>
                        <h3>{{ __('OPAL for Revit') }}</h3>
                        <p>
                            @if ($this->release)
                                {{ __('Version :version · :channel channel', ['version' => $this->release['version'], 'channel' => $this->release['channel_label']]) }}
                            @else
                                {{ __('The first native installer is being prepared.') }}
                            @endif
                        </p>
                    </div>
                    @if ($this->release)
                        <x-ui.badge tone="success" dot>{{ __('Available') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="warning">{{ __('Pending') }}</x-ui.badge>
                    @endif
                </div>

                <p class="ui-lede">
                    {{ __('Install once, open Revit, then choose OPAL → Settings to connect your account. Updates download in the background and activate the next time Revit starts.') }}
                </p>

                <div class="ui-actions" style="margin-top: 16px">
                    @if ($this->release)
                        <x-ui.button
                            href="{{ $this->release['installer']['url'] }}"
                            variant="primary"
                            data-test="download-revit"
                        >{{ __('Download Windows installer') }}</x-ui.button>
                        <span class="ui-code">{{ number_format($this->release['installer']['bytes'] / 1048576, 1) }} MB</span>
                    @else
                        <x-ui.button variant="primary" disabled>{{ __('Download coming shortly') }}</x-ui.button>
                    @endif
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>{{ __('Compatibility') }}</h3></div></div>
                <ul class="ui-list">
                    @foreach ($this->supportedVersions as $year => $target)
                        <li>
                            <span>{{ __('Revit :year · :runtime', ['year' => $year, 'runtime' => $target['runtime']]) }}</span>
                            <x-ui.badge :tone="$target['verification'] === 'Tested in Revit' ? 'success' : 'info'">
                                {{ __($target['verification']) }}
                            </x-ui.badge>
                        </li>
                    @endforeach
                </ul>
                <p class="ui-modal__note" style="margin-top: 12px">
                    {{ __('The installer detects installed Revit versions and lets you choose additional versions for custom installations.') }}
                </p>
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>{{ __('What gets installed') }}</h3></div></div>
                <ul class="ui-list">
                    <li><span>{{ __('Native Revit ribbon and material tools') }}</span><x-ui.badge tone="info">{{ __('No pyRevit') }}</x-ui.badge></li>
                    <li><span>{{ __('Account connection and live status') }}</span><x-ui.badge tone="neutral">{{ __('OPAL Settings') }}</x-ui.badge></li>
                    <li><span>{{ __('Automatic, verified updates') }}</span><x-ui.badge tone="neutral">{{ __('On restart') }}</x-ui.badge></li>
                </ul>
            </x-ui.panel>

            <p class="ui-modal__note">
                {{ __('Revit 2025–2027 on supported Windows versions is required. Revit LT does not load add-ins. Revit 2027 is host-tested; 2025 and 2026 are compiled against their exact Autodesk APIs and remain beta until tested in those hosts.') }}
            </p>
        </div>
    </x-pages::settings.layout>
</section>
