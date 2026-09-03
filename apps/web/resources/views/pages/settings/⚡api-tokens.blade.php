<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    public string $name = '';

    public ?string $plainTextToken = null;

    #[Computed]
    public function tokens(): \Illuminate\Support\Collection
    {
        return Auth::user()->tokens()->orderByDesc('created_at')->get();
    }

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:60']]);

        $this->plainTextToken = Auth::user()->createToken($this->name)->plainTextToken;
        $this->reset('name');
        unset($this->tokens);
    }

    public function revoke(int $tokenId): void
    {
        Auth::user()->tokens()->whereKey($tokenId)->delete();
        unset($this->tokens);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('API tokens')" :subheading="__('Bearer tokens for scripts, the Revit extension and other clients. Documentation is at /docs/api.')">
        @if ($plainTextToken)
            <flux:callout variant="warning" icon="key" class="mb-6" data-test="new-token">
                <flux:callout.heading>{{ __('Copy this token now; it will not be shown again.') }}</flux:callout.heading>
                <flux:callout.text><code class="block break-all text-xs">{{ $plainTextToken }}</code></flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="create" class="flex items-end gap-3">
            <flux:input wire:model="name" :label="__('Token name')" placeholder="pyRevit on my workstation" data-test="token-name" />
            <flux:button type="submit" variant="primary" data-test="create-token">{{ __('Create') }}</flux:button>
        </form>

        <ul class="mt-6 divide-y divide-zinc-200">
            @forelse ($this->tokens as $token)
                <li class="flex items-center justify-between gap-3 py-3 text-sm" wire:key="token-{{ $token->id }}" data-test="token-row">
                    <span>
                        <span class="font-medium">{{ $token->name }}</span>
                        <span class="text-xs text-zinc-500">· {{ __('created :when', ['when' => $token->created_at?->diffForHumans()]) }}@if ($token->last_used_at) · {{ __('last used :when', ['when' => $token->last_used_at->diffForHumans()]) }}@endif</span>
                    </span>
                    <flux:button wire:click="revoke({{ $token->id }})" size="sm" variant="ghost" data-test="revoke-token">{{ __('Revoke') }}</flux:button>
                </li>
            @empty
                <li class="py-3 text-sm text-zinc-500">{{ __('No tokens yet.') }}</li>
            @endforelse
        </ul>
    </x-pages::settings.layout>
</section>
