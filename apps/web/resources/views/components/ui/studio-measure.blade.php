@props([
    'label',
    'model',
    'value',
    'min' => 0,
    'max' => 100000,
    'step' => 0.1,
    'suffix' => 'mm',
])

<label class="ui-studio-measure" wire:key="studio-measure-{{ str_replace('.', '-', $model) }}">
    <span>{{ $label }}</span>
    <span>
        <input
            type="number"
            min="{{ $min }}"
            max="{{ $max }}"
            step="{{ $step }}"
            value="{{ $value }}"
            wire:model.live.debounce.450ms="{{ $model }}"
        />
        <em>{{ $suffix }}</em>
    </span>
    @error($model)<small class="ui-field__error">{{ $message }}</small>@enderror
</label>
