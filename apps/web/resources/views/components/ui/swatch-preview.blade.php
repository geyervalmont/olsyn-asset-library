@props([
    'card',
    'targets' => [],
    'tag' => null,
    'variantsCount' => null,
    'name' => '',
    'open' => false,
])

@php
    $first = $card['variants'][$card['active']] ?? ['hex' => '#cfcbc1', 'image' => null, 'name' => ''];
    $labels = \App\Library\Previews\MaterialPreviews::badgeLabels();
@endphp

<div {{ $attributes->class(['ui-swatch-card__preview', 'ui-swatch-card__preview--open' => $open]) }}>
    <div class="ui-swatch-card__fill" style="--chip: {{ $first['hex'] }}" x-bind:style="current ? '--chip: ' + current.hex : ''" aria-hidden="true"></div>
    <img
        src="{{ $first['image'] ?? '' }}"
        alt="{{ $name }}"
        loading="lazy"
        @if ($first['image'] === null) hidden @endif
        x-bind:src="current?.image ?? ''"
        x-bind:hidden="! current?.image"
    />
    @if ($tag)
        <span class="ui-swatch-card__tag">{{ $tag }}</span>
    @endif
    <div class="ui-swatch-card__badges" aria-label="{{ __('Available files') }}">
        @forelse ($targets as $slug => $state)
            <span class="ui-tag" data-badge="{{ $slug }}" data-state="{{ $state }}" title="{{ ucfirst($slug) }} · {{ $state }}">{{ $labels[$slug] ?? strtoupper($slug) }}</span>
        @empty
            <span class="ui-tag ui-tag--faint" data-badge="none">{{ __('No files') }}</span>
        @endforelse
    </div>
    <span class="ui-swatch-card__label" x-text="current?.name ?? ''">{{ $first['name'] }}</span>
    @if (count($card['variants']) > 1)
        <div class="ui-swatch-card__colourways" role="group" aria-label="{{ __('Colourways') }}" data-test="colourways">
            @foreach ($card['variants'] as $index => $chip)
                <button
                    type="button"
                    title="{{ $chip['name'] }}"
                    style="--chip: {{ $chip['hex'] }};@if ($chip['image']) background-image: url('{{ $chip['image'] }}')@endif"
                    x-on:mouseenter="pick({{ $index }})"
                    x-on:click.prevent.stop="pick({{ $index }})"
                    x-bind:class="active === {{ $index }} && 'is-active'"
                ></button>
            @endforeach
            @if ($variantsCount !== null && $variantsCount > count($card['variants']))
                <span>+{{ $variantsCount - count($card['variants']) }}</span>
            @endif
        </div>
    @endif
</div>
