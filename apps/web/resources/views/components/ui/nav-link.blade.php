@props(['href', 'label', 'icon', 'active' => false])

<a
    href="{{ $href }}"
    {{ $attributes->class(['ui-app-nav__link', 'is-active' => $active]) }}
    @if ($active) aria-current="page" @endif
    aria-label="{{ $label }}"
    x-bind:title="sidebarCollapsed ? @js($label) : null"
    wire:navigate
>
    <x-ui.nav-icon :name="$icon" />
    <span class="ui-app-nav__text">{{ $label }}</span>
</a>
