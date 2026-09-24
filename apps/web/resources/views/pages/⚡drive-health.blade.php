<?php

use App\Models\DriveConnection;
use App\Models\DriveTelemetryEvent;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Drive health')] class extends Component {
    use WithPagination;

    #[Url]
    public string $device = '';

    public function updatedDevice(): void { $this->resetPage(); }

    private function scope(Builder $query): Builder
    {
        // Recheck on every Livewire request. Only super-admins can inspect other accounts.
        abort_unless(auth()->check(), 403);
        return $query->when(! auth()->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->when($this->device !== '', fn ($q) => $q->where('device_id', $this->device));
    }

    #[Computed]
    public function events() { return $this->scope(DriveTelemetryEvent::query())->with('user')->latest('received_at')->latest('id')->paginate(25); }

    #[Computed]
    public function devices() { return $this->scope(DriveConnection::query())->with('user')->latest('last_seen_at')->limit(100)->get(); }

    #[Computed]
    public function errors() { return $this->scope(DriveTelemetryEvent::query())->where('kind', 'error')->where('received_at', '>=', now()->subDay())->count(); }

    #[Computed]
    public function versions() { return $this->scope(DriveTelemetryEvent::query())->where('kind', 'error')->where('received_at', '>=', now()->subDay())->selectRaw('version, code, count(*) as total')->groupBy('version', 'code')->orderByDesc('total')->limit(10)->get(); }
}; ?>

<section class="ui-connect" wire:poll.15s data-test="drive-health">
    <div class="ui-section-head">
        <div><x-ui.eyebrow>{{ auth()->user()->isSuperAdmin() ? __('All accounts · Support') : __('Your devices') }}</x-ui.eyebrow><h1>{{ __('Drive health') }}</h1></div>
        <a href="{{ route('connect') }}" wire:navigate>{{ __('Back to Connect') }}</a>
    </div>
    <x-ui.panel>
        <h2>{{ __(':count error reports received in the last 24 hours', ['count' => $this->errors]) }}</h2>
        <p>{{ __('OPAL Drive 0.1.3 and later report operational errors automatically when enabled. Offline reports arrive after reconnection. Repeated identical errors are sampled once per five minutes. History is retained for 30 days.') }}</p>
        <p>{{ __('Reports contain device ID, app and Windows versions, the failed step, timing and error codes. They exclude credentials, file paths, material contents and exception messages.') }}</p>
        <label for="health-device">{{ __('Filter by device ID') }}</label>
        <input id="health-device" class="ui-input" wire:model.live.debounce.500ms="device" maxlength="36" placeholder="{{ __('All devices') }}">
        @foreach ($this->versions as $version)
            <p><x-ui.badge tone="warning">{{ $version->total }}</x-ui.badge> {{ __('Version') }} {{ $version->version }} · <code>{{ $version->code }}</code></p>
        @endforeach
    </x-ui.panel>
    <x-ui.panel>
        <h2>{{ __('Latest device status') }}</h2>
        <p>{{ __('A missing heartbeat means the computer may be asleep, offline or the app closed. It does not by itself confirm a failure. Showing the 100 most recently seen devices.') }}</p>
        @forelse ($this->devices as $connection)
            <div class="ui-team__row" wire:key="device-{{ $connection->id }}">
                <div class="ui-team__person"><strong>{{ $connection->machine }}</strong>
                    @if (auth()->user()->isSuperAdmin())<small>{{ $connection->user->name }}</small>@endif
                    <small>{{ $connection->device_id }} · {{ __('Version') }} {{ $connection->version ?: '—' }} · {{ $connection->last_seen_at->diffForHumans() }}</small>
                    @if ($connection->error_code)<small>{{ __('Last reported error') }}: {{ $connection->error_code }}</small>@endif
                </div>
                <x-ui.badge :tone="$connection->isLive() && $connection->state === 'error' ? 'warning' : ($connection->isLive() && $connection->state === 'mounted' ? 'success' : 'neutral')">{{ $connection->isLive() ? ucfirst($connection->state) : __('Offline / not reporting') }}</x-ui.badge>
            </div>
        @empty
            <p>{{ __('No device heartbeats received yet.') }}</p>
        @endforelse
    </x-ui.panel>
    <x-ui.panel>
        <h2>{{ __('Error and connection history') }}</h2>
        <p>{{ __('“Drive ready” confirms mounting and library access at that time; individual file or upload errors remain in this history. Times below are UTC and include when delayed reports reached OPAL.') }}</p>
        @forelse ($this->events as $event)
            <article class="ui-team__row" wire:key="event-{{ $event->id }}">
                <div class="ui-team__person">
                    <strong>{{ $event->kind === 'ready' ? __('Drive ready') : $event->stage.' · '.$event->code }}</strong>
                    <small>{{ $event->occurred_at->format('Y-m-d H:i:s') }} UTC · {{ __('Version') }} {{ $event->version }} · Windows {{ $event->details['os_version'] }}</small>
                    <small>{{ $event->device_id }}@if (auth()->user()->isSuperAdmin()) · {{ $event->user->name }}@endif</small>
                    <details><summary>{{ __('Technical details') }}</summary>
                        <p>{{ __('Report') }} {{ $event->event_id }} · {{ __('Received') }} {{ $event->received_at->format('Y-m-d H:i:s') }} UTC</p>
                        <p>HRESULT {{ $event->details['hresult'] ?? '—' }} · HTTP {{ $event->details['http_status'] ?? '—' }} · Win32 {{ $event->details['native_error'] ?? '—' }} · Dokan {{ $event->details['driver_status'] ?? '—' }} · {{ $event->details['elapsed_ms'] ?? '—' }} ms</p>
                    </details>
                </div>
                <x-ui.badge :tone="$event->kind === 'error' ? 'warning' : 'success'">{{ $event->kind === 'error' ? __('Error') : __('Ready') }}</x-ui.badge>
            </article>
        @empty
            <p>{{ __('No reports received. Older clients, disabled reporting and disconnected computers may have no history here.') }}</p>
        @endforelse
        {{ $this->events->links() }}
    </x-ui.panel>
</section>
