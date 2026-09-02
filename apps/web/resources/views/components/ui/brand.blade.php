@props([
    'href' => null,
])

@php
    $content = <<<'HTML'
        <span class="ui-brand__mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" fill="none">
                <path d="M5.5 6.5h13v13h-13zM13.5 12.5h13v13h-13z" />
                <path class="ui-brand__accent" d="M20 6.5h6.5V13H20z" />
            </svg>
        </span>
        <span class="ui-brand__words">
            <strong>OLSYN</strong>
            <small>Asset library</small>
        </span>
    HTML;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class('ui-brand') }}>
        {!! $content !!}
    </a>
@else
    <span {{ $attributes->class('ui-brand') }}>
        {!! $content !!}
    </span>
@endif
