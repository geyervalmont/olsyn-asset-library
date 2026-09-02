@props([
    'label',
    'value',
    'detail' => null,
    'trend' => null,
])

<div {{ $attributes->class('ui-stat') }}>
    <span class="ui-stat__label">{{ $label }}</span>
    <strong class="ui-stat__value">{{ $value }}</strong>
    @if ($detail || $trend)
        <span class="ui-stat__detail">
            @if ($trend)
                <em>{{ $trend }}</em>
            @endif
            {{ $detail }}
        </span>
    @endif
</div>
