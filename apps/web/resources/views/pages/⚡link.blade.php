<?php

use App\Actions\Clients\ClaimDeviceLink;
use App\Models\DeviceLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Connect a device')] class extends Component {
    #[Url]
    public string $code = '';

    public bool $linked = false;

    #[Computed]
    public function link(): ?DeviceLink
    {
        return $this->code === '' ? null : DeviceLink::findByCode($this->code);
    }

    public function claim(ClaimDeviceLink $claim): void
    {
        $this->validate(['code' => ['required', 'string', 'min:8', 'max:9']]);

        $link = $this->link;

        if ($link === null) {
            $this->addError('code', __('No client is waiting with that code.'));

            return;
        }

        $claim->handle($link, Auth::user());

        $this->linked = true;
        unset($this->link);
    }
}; ?>

<section class="ui-narrow">
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Connect') }}</x-ui.eyebrow>
            <h1>{{ __('Connect a device') }}</h1>
        </div>
    </div>

    <x-ui.panel data-test="link-panel">
        @if ($linked)
            <p class="ui-lede" data-test="link-done">{{ __('Your device is connected. Return to the application; it will finish signing in automatically.') }}</p>
            <x-ui.button href="{{ route('connect') }}" variant="secondary" size="sm">{{ __('View your connections') }}</x-ui.button>
        @else
            <p class="ui-lede">{{ __('Enter the code shown by your OPAL application. It will connect using your account and permissions.') }}</p>

            <p class="ui-modal__note" style="margin-top: 12px">{{ __('Connecting as :email', ['email' => auth()->user()->email]) }}</p>
            <form wire:submit="claim" class="ui-form-row" style="margin-top: 16px">
                <x-ui.field :label="__('Code')" for="link-code" :error="$errors->first('code')">
                    <input id="link-code" class="ui-input" wire:model.live.debounce.300ms="code" placeholder="XXXX-XXXX" autocomplete="off" spellcheck="false" style="font-family: 'IBM Plex Mono', ui-monospace, monospace; letter-spacing: 0.08em; text-transform: uppercase" data-test="link-code" />
                </x-ui.field>
                <x-ui.button type="submit" data-test="link-claim">{{ __('Connect this device') }}</x-ui.button>
            </form>

            @if ($this->link)
                <dl class="ui-kv" style="margin-top: 16px" data-test="link-details">
                    <dt>{{ __('Client') }}</dt><dd>{{ $this->link->label() }}</dd>
                    @if ($this->link->app_version)<dt>{{ __('Version') }}</dt><dd>{{ $this->link->app_version }}</dd>@endif
                    <dt>{{ __('State') }}</dt>
                    <dd>
                        @if ($this->link->isClaimed()) {{ __('already linked') }}
                        @elseif ($this->link->isExpired()) {{ __('expired; start again in the client') }}
                        @else {{ __('waiting, expires :when', ['when' => $this->link->expires_at->diffForHumans()]) }}
                        @endif
                    </dd>
                </dl>
            @endif
        @endif
    </x-ui.panel>
</section>
