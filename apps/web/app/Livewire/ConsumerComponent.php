<?php

namespace App\Livewire;

use App\Models\ClientCommand;
use App\Models\ClientSession;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** @property-read Collection<int, ClientSession> $consumerSessions */
abstract class ConsumerComponent extends Component
{
    #[Locked]
    public int $userId = 0;

    public ?int $consumerSessionId = null;

    #[Locked]
    public ?int $consumerCommandId = null;

    public function mountConsumers(): void
    {
        $this->userId = (int) auth()->id();
        $sessions = $this->consumerSessions;
        $this->consumerSessionId = $sessions->count() === 1 ? $sessions->first()->id : null;
    }

    protected function consumerCapability(): string
    {
        return 'material.apply';
    }

    /** @return Collection<int, ClientSession> */
    #[Computed]
    public function consumerSessions(): Collection
    {
        return ClientSession::query()->where('user_id', auth()->id())->live()->orderBy('platform')->orderBy('machine')->orderBy('id')->get()
            ->filter(fn (ClientSession $session): bool => $session->supports($this->consumerCapability()))->values();
    }

    public function selectedConsumer(): ?ClientSession
    {
        // Computed data is scoped to this request; never redirect a stale selection.
        $sessions = $this->consumerSessions;

        return $this->consumerSessionId !== null
            ? $sessions->firstWhere('id', $this->consumerSessionId)
            : ($sessions->count() === 1 ? $sessions->first() : null);
    }

    public function consumerBlockedReason(): ?string
    {
        if ($this->consumerSessions->isEmpty()) {
            return __('No compatible app connected. Connect an updated OPAL extension with this account.');
        }

        return $this->selectedConsumer() === null ? (string) __('Choose a connected application to apply this material.') : null;
    }

    public function applyLabel(): string
    {
        $session = $this->selectedConsumer();

        return $session ? __('Apply in :app', ['app' => $session->platformLabel()]) : __('Apply material');
    }

    #[Computed]
    public function consumerCommand(): ?ClientCommand
    {
        return $this->consumerCommandId === null ? null : ClientCommand::query()->with('session')->whereKey($this->consumerCommandId)->where('issued_by', auth()->id())->first();
    }

    #[On('echo-private:user.{userId},.command.acked')]
    #[On('echo-private:user.{userId},.command.completed')]
    public function consumerCommandUpdated(): void
    {
        unset($this->consumerCommand);
    }

    #[On('echo-private:user.{userId},.session.updated')]
    public function consumerSessionsUpdated(): void
    {
        unset($this->consumerSessions, $this->consumerCommand);
    }
}
