@auth
    @php
        $live = \App\Models\ClientSession::query()->where('user_id', auth()->id())->live()->orderBy('platform')->get();
        $seeded = $live->map(fn ($session): array => $session->toBroadcast() + ['secondsAgo' => (int) $session->last_seen_at->diffInSeconds(now())])->values();
        $label = $live->groupBy('platform')->map(fn ($sessions) => $sessions->first()->platformLabel().($sessions->count() > 1 ? ' ('.$sessions->count().')' : ''))->join(' · ');
    @endphp
    <a
        class="ui-consumer-pill"
        href="{{ route('connect') }}"
        wire:navigate
        data-test="consumer-status"
        x-data="consumerStatus(@js(['userId' => (int) auth()->id(), 'sessions' => $seeded, 'liveSeconds' => \App\Models\ClientSession::liveSeconds(), 'url' => route('connect.sessions')]))"
        x-bind:data-state="state"
        x-bind:title="title"
        data-state="{{ $live->isEmpty() ? 'off' : 'live' }}"
        aria-live="polite"
    >
        <span class="ui-status-light" aria-hidden="true"></span>
        <span class="ui-consumer-pill__label" x-text="label">{{ $label ?: __('Nothing connected') }}</span>
    </a>
@endauth
