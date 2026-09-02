@props([
    'tone' => 'paper',
    'padding' => true,
])

<div {{ $attributes->class([
    'ui-panel',
    'ui-panel--'.$tone,
    'ui-panel--padded' => $padding,
]) }}>
    {{ $slot }}
</div>
