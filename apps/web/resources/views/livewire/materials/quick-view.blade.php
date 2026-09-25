@php
    $material = $this->quickMaterial;
    $seed = $material ? ['code' => $material->code, 'name' => $material->name, 'category' => $material->category->name] : null;
@endphp

<div
    x-data="materialQuickView"
    data-initial="{{ json_encode($seed) }}"
    x-on:material-open.window="open($event.detail)"
    x-on:keydown.escape.window="if (visible) close()"
    x-on:popstate.window="syncLocation()"
    data-test="quick-view"
>
    <div class="ui-modal" x-show="visible" x-cloak data-test="quick-dialog">
        <button type="button" class="ui-modal__scrim" x-on:click="close()" tabindex="-1" aria-label="{{ __('Close') }}"></button>
        <div class="ui-modal__panel" role="dialog" aria-modal="true" aria-labelledby="quick-title" tabindex="-1" x-trap.inert.noscroll="visible">
            <header class="ui-modal__head">
                <div>
                    <x-ui.eyebrow><span x-text="seed?.category || '{{ __('Material') }}'"></span></x-ui.eyebrow>
                    <h2 id="quick-title" x-text="seed?.name || '{{ __('Material') }}'"></h2>
                    <p class="ui-modal__code"><code data-test="quick-code" x-text="seed?.code"></code></p>
                </div>
                <button type="button" class="ui-modal__close" x-on:click="close()" aria-label="{{ __('Close') }}" data-test="quick-close">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
                </button>
            </header>

            <div class="ui-modal__pending" x-show="!ready" x-bind:aria-busy="!error" data-test="quick-pending">
                <div class="ui-modal__placeholder" x-bind:style="{ backgroundColor: seed?.hex || '#e8eae5' }">
                    <template x-if="visible && seed?.image"><img x-bind:src="seed.image" alt="" /></template>
                    <p role="status" x-text="error || '{{ __('Opening 3D preview…') }}'"></p>
                </div>
                <button type="button" class="ui-button ui-button--secondary" x-show="error" x-on:click="open(seed)">{{ __('Try again') }}</button>
            </div>

            <div class="ui-modal__content" wire:key="quick-content-{{ $requestId }}-{{ $material?->id ?? 'none' }}">
                @if ($material)
                    <template x-if="visible && request === {{ $requestId }}">
                        <x-ui.material-modal
                            :material="$material"
                            :card="$this->quickCard"
                            :targets="$this->quickTargets"
                            :variants-count="$material->variants_count"
                            :sessions="$this->consumerSessions"
                            :command="$this->consumerCommand"
                            :blocked="$this->applyBlockedReason()"
                            :sets="$this->quickViewerSets"
                        />
                    </template>
                @endif
            </div>
        </div>
    </div>
</div>
