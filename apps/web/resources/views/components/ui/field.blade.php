@props([
    'label',
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->class('ui-field') }}>
    <div class="ui-field__label-row">
        <label class="ui-field__label" @if ($for) for="{{ $for }}" @endif>
            {{ $label }}
        </label>
        @if ($required)
            <span class="ui-field__required">Required</span>
        @endif
    </div>

    {{ $slot }}

    @if ($error)
        <p class="ui-field__error">{{ $error }}</p>
    @elseif ($hint)
        <p class="ui-field__hint">{{ $hint }}</p>
    @endif
</div>
