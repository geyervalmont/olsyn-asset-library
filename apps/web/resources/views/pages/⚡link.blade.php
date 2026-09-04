<?php

use App\Actions\Clients\ClaimDeviceLink;
use App\Models\DeviceLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Link a client')] class extends Component {
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
            <x-ui.eyebrow>{{ __('Clients') }}</x-ui.eyebrow>
            <h1>{{ __('Link a client') }}</h1>
        </div>
    </div>

    <x-ui.panel data-test="link-panel">
        @if ($linked)
            <p class="ui-lede" data-test="link-done">{{ __('Linked. Back in the client, the link finishes on its own within a few seconds. The token is listed under Settings → API tokens and can be revoked there.') }}</p>
            <x-ui.button href="{{ route('api-tokens.edit') }}" variant="secondary" size="sm">{{ __('API tokens') }}</x-ui.button>
        @else
            <p class="ui-lede">{{ __('Enter the code the client shows. Linking signs the client in as you: it can read what you can read and apply materials in your Revit sessions.') }}</p>

            <form wire:submit="claim" class="ui-form-row" style="margin-top: 16px">
                <x-ui.field :label="__('Code')" for="link-code" :error="$errors->first('code')">
                    <input id="link-code" class="ui-input" wire:model.live.debounce.300ms="code" placeholder="XXXX-XXXX" autocomplete="off" spellcheck="false" style="font-family: 'IBM Plex Mono', ui-monospace, monospace; letter-spacing: 0.08em; text-transform: uppercase" data-test="link-code" />
                </x-ui.field>
                <x-ui.button type="submit" data-test="link-claim">{{ __('Link this client') }}</x-ui.button>
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
