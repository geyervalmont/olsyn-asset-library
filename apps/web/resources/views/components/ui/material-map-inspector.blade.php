@props(['compact' => false, 'tileable' => false])

<div
    @class(['ui-material-maps', 'ui-material-maps--compact' => $compact])
    x-show="maps.length > 0"
    x-cloak
    data-test="material-map-inspector"
>
    <div
        class="ui-material-maps__image"
        x-show="view === 'map' && activeMap"
        x-transition.opacity
        x-cloak
        data-test="material-map-view"
    >
        <img x-bind:src="activeMap?.url" x-bind:alt="activeMap ? `${activeMap.label} map` : ''" />
        <p>
            <strong x-text="activeMap?.label"></strong>
            <span>{{ __('Raw map · no material shading') }}</span>
        </p>
    </div>

    @if ($tileable)
        <div
            class="ui-material-maps__tile"
            x-show="view === 'tile' && activeMap"
            x-transition.opacity
            x-cloak
            data-test="material-tile-view"
        >
            <span x-bind:style="activeMap ? `background-image: url('${activeMap.url}')` : ''"></span>
            <p>
                <strong x-text="activeMap?.label"></strong>
                <span>{{ __('4-up repeat · inspect every seam') }}</span>
            </p>
        </div>
    @endif

    <div class="ui-material-maps__rail" role="tablist" aria-label="{{ __('Material views') }}">
        <button
            type="button"
            role="tab"
            title="{{ __('Rendered surface') }}"
            x-on:click.stop="inspectSurface()"
            x-bind:aria-selected="view === 'surface'"
            x-bind:class="view === 'surface' && 'is-active'"
            data-test="material-map-surface"
        >
            <span class="ui-material-maps__surface" aria-hidden="true"></span>
            <em>{{ __('Surface') }}</em>
        </button>

        @if ($tileable)
            <button
                type="button"
                role="tab"
                title="{{ __('Tiled repeat') }}"
                x-on:click.stop="inspectTile()"
                x-bind:aria-selected="view === 'tile'"
                x-bind:class="view === 'tile' && 'is-active'"
                data-test="material-map-tile"
            >
                <span class="ui-material-maps__repeat" aria-hidden="true"></span>
                <em>{{ __('Repeat') }}</em>
            </button>
        @endif

        <template x-for="map in maps" x-bind:key="map.role">
            <button
                type="button"
                role="tab"
                x-bind:title="map.label"
                x-on:click.stop="inspectMap(map.role)"
                x-bind:aria-selected="view === 'map' && mapRole === map.role"
                x-bind:class="view === 'map' && mapRole === map.role && 'is-active'"
                x-bind:data-role="map.role"
            >
                <img x-bind:src="map.url" alt="" loading="lazy" />
                <em x-text="map.shortLabel"></em>
            </button>
        </template>
    </div>
</div>
