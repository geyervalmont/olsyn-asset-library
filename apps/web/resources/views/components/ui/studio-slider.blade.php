@props([
    'label',
    'model',
    'value',
    'min' => 0,
    'max' => 1,
    'step' => 0.01,
    'suffix' => '',
])

<label class="ui-studio-control" wire:key="studio-control-{{ str_replace('.', '-', $model) }}">
    <span class="ui-studio-control__label">
        <strong>{{ $label }}</strong>
        <span><output>{{ $value }}</output>{{ $suffix }}</span>
    </span>
    <span class="ui-studio-control__range">
        <input
            type="range"
            min="{{ $min }}"
            max="{{ $max }}"
            step="{{ $step }}"
            value="{{ $value }}"
            wire:model.live.debounce.450ms="{{ $model }}"
            aria-label="{{ $label }}"
        />
        <input
            class="ui-studio-control__number"
            type="number"
            min="{{ $min }}"
            max="{{ $max }}"
            step="{{ $step }}"
            value="{{ $value }}"
            wire:model.live.debounce.450ms="{{ $model }}"
            aria-label="{{ $label }} {{ __('value') }}"
        />
    </span>
    @error($model)<small class="ui-field__error">{{ $message }}</small>@enderror
</label>
