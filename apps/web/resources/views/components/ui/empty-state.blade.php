@props([
    'title',
    'description',
])

<div {{ $attributes->class('ui-empty') }}>
    <div class="ui-empty__drawing" aria-hidden="true">
        <svg viewBox="0 0 120 84" fill="none">
            <path d="M17 63.5 45 45l27 9 31-20v30.5H17z" />
            <path d="M17 63.5h86M45 45v18.5M72 54v9.5M31 54l27-18 30 10" />
            <circle cx="88" cy="25" r="7" />
        </svg>
    </div>
    <strong>{{ $title }}</strong>
    <p>{{ $description }}</p>
    @if (isset($action))
        <div class="ui-empty__action">{{ $action }}</div>
    @endif
</div>
