@php
    /** Whether this person has a Revit listening right now. */
    $live = auth()->check()
        ? \App\Models\ClientSession::query()
            ->where('user_id', auth()->id())
            ->live()
            ->orderByDesc('last_seen_at')
            ->get(['id', 'machine', 'document', 'last_seen_at'])
        : collect();

    $seeded = $live->map(fn ($session): array => [
        'id' => $session->id,
        'machine' => $session->machine,
        'document' => $session->document,
        'secondsAgo' => (int) $session->last_seen_at->diffInSeconds(now()),
    ])->values();
@endphp

@auth
    <a
        class="ui-revit-pill"
        href="{{ route('sessions.edit') }}"
        wire:navigate
        data-test="revit-status"
        x-data="revitStatus(@js(['userId' => (int) auth()->id(), 'sessions' => $seeded, 'liveSeconds' => \App\Models\ClientSession::liveSeconds()]))"
        x-bind:data-state="state"
        x-bind:title="title"
        data-state="{{ $live->isEmpty() ? 'off' : 'live' }}"
    >
        <span class="ui-status-light" aria-hidden="true"></span>
        <span class="ui-revit-pill__label" x-text="label">{{ $live->first()?->machine ?? __('No Revit') }}</span>
    </a>
@endauth
