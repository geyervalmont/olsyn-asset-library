@props([
    'label',
    'model',
    'value',
    'id',
])

<div class="ui-studio-colour" style="--studio-swatch: {{ $value }}" wire:key="studio-colour-{{ str_replace('.', '-', $model) }}">
    <label class="ui-studio-colour__swatch" for="{{ $id }}">
        <input
            id="{{ $id }}"
            type="color"
            value="{{ $value }}"
            wire:model.live.debounce.350ms="{{ $model }}"
            aria-label="{{ $label }}"
        />
        <span aria-hidden="true"></span>
    </label>
    <label class="ui-studio-colour__value">
        <strong>{{ $label }}</strong>
        <input
            type="text"
            value="{{ $value }}"
            wire:model.live.debounce.450ms="{{ $model }}"
            maxlength="7"
            spellcheck="false"
            aria-label="{{ $label }} {{ __('hex value') }}"
        />
    </label>
    @error($model)<small class="ui-field__error">{{ $message }}</small>@enderror
</div>
