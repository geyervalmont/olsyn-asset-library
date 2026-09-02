@props([
    'title',
    'meta' => 'Blade template',
])

<article {{ $attributes->class('ui-template') }}>
    <header class="ui-template__header">
        <div>
            <small>{{ $meta }} / template</small>
            <strong>{{ $title }}</strong>
        </div>
        <span>Base screen</span>
    </header>
    <div class="ui-template__body">
        {{ $slot }}
    </div>
</article>
