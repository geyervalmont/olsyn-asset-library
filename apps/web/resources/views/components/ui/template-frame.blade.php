@props([
    'title',
    'meta' => 'Blade template',
])

<article {{ $attributes->class('ui-template') }}>
    <header class="ui-template__header">
        <div>
            <small>{{ $meta }}</small>
            <strong>{{ $title }}</strong>
        </div>
    </header>
    <div class="ui-template__body">
        {{ $slot }}
    </div>
</article>
