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
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Revit extension')" :subheading="__('Connect Revit directly to OPAL. pyRevit and Python are not required.')">
        <div class="ui-stack" data-test="revit-download">
            <x-ui.panel tone="paper">
                <div class="ui-panel__heading">
                    <div>
                        <h3>{{ __('OPAL for Revit 2027') }}</h3>
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
                        >{{ __('Download for Revit 2027') }}</x-ui.button>
                        <span class="ui-code">{{ number_format($this->release['installer']['bytes'] / 1048576, 1) }} MB</span>
                    @else
                        <x-ui.button variant="primary" disabled>{{ __('Download coming shortly') }}</x-ui.button>
                    @endif
                </div>
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
                {{ __('Revit 2027 on Windows 11 is required. Revit LT does not load add-ins.') }}
            </p>
        </div>
    </x-pages::settings.layout>
</section>
