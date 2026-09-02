@props([
    'tone' => 'neutral',
    'dot' => false,
])

<span {{ $attributes->class(['ui-badge', 'ui-badge--'.$tone]) }}>
    @if ($dot)
        <span class="ui-badge__dot" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
