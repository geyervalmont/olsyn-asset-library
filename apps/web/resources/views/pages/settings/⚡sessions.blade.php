<?php

use App\Actions\Clients\TouchClientSession;
use App\Models\ClientSession;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Revit sessions')] class extends Component {
    public int $userId = 0;

    public function mount(): void
    {
        $this->userId = (int) Auth::id();
    }

    #[Computed]
    public function sessions(): \Illuminate\Support\Collection
    {
        return ClientSession::query()
            ->where('user_id', $this->userId)
            ->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subDay())
            ->orderByDesc('last_seen_at')
            ->get();
    }

    #[On('echo-private:user.{userId},.session.updated')]
    public function refresh(): void
    {
        unset($this->sessions);
    }

    public function end(int $sessionId, TouchClientSession $touch): void
    {
        $session = ClientSession::query()->whereKey($sessionId)->where('user_id', $this->userId)->firstOrFail();
        $touch->end($session);
        unset($this->sessions);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Revit sessions')" :subheading="__('Clients linked to your account that are running now. Commands from the library go to these.')">
        <ul class="mt-2 divide-y divide-zinc-200" wire:poll.60s>
            @forelse ($this->sessions as $session)
                <li class="flex items-center justify-between gap-3 py-3 text-sm" wire:key="session-{{ $session->id }}" data-test="session-row">
                    <span>
                        <span class="ui-status-light {{ $session->isLive() ? '' : 'ui-status-light--stale' }}" aria-hidden="true"></span>
                        <span class="font-medium">{{ $session->label() }}</span>
                        <span class="text-xs text-zinc-500">· {{ $session->isLive() ? __('live') : __('last seen :when', ['when' => $session->last_seen_at->diffForHumans()]) }}</span>
                    </span>
                    <flux:button wire:click="end({{ $session->id }})" size="sm" variant="ghost" data-test="end-session">{{ __('End') }}</flux:button>
                </li>
            @empty
                <li class="py-3 text-sm text-zinc-500" data-test="no-sessions">{{ __('No client is connected. In Revit, OPAL → Connect links this account and keeps a session open while Revit runs.') }}</li>
            @endforelse
        </ul>
    </x-pages::settings.layout>
</section>
