@props([
    'index' => null,
])

<p {{ $attributes->class('ui-eyebrow') }}>
    @if ($index)
        <span>{{ $index }}</span>
    @endif
    {{ $slot }}
</p>
