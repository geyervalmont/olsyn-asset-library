@props([
    'title',
    'meta' => 'Blade template',
])

<article {{ $attributes->class('ui-template') }}>
    <header class="ui-template__header">
        <span class="ui-window-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span>{{ $title }}</span>
        <small>{{ $meta }}</small>
    </header>
    <div class="ui-template__body">
        {{ $slot }}
    </div>
</article>
