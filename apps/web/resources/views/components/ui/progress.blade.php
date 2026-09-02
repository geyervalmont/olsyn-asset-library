@props([
    'value' => 0,
    'label' => null,
])

<div {{ $attributes->class('ui-progress') }}>
    @if ($label)
        <div class="ui-progress__meta">
            <span>{{ $label }}</span>
            <span>{{ $value }}%</span>
        </div>
    @endif
    <div class="ui-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $value }}">
        <span style="width: {{ min(100, max(0, (int) $value)) }}%"></span>
    </div>
</div>
