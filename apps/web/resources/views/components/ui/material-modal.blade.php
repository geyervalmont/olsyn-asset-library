@props([
    'material',
    'card',
    'targets' => [],
    'variantsCount' => 0,
    'sessions',
    'command' => null,
    'blocked' => null,
])

@php
    $status = match ($material->status->value) { 'active' => 'success', 'archived' => 'neutral', default => 'warning' };
@endphp

<div
    class="ui-modal"
    wire:key="quick-{{ $material->id }}"
    x-data="swatchCard(@js($card))"
    x-on:keydown.escape.window="$wire.closeQuick()"
    data-test="material-modal"
>
    <button type="button" class="ui-modal__scrim" wire:click="closeQuick" tabindex="-1" aria-label="{{ __('Close') }}"></button>

    <div class="ui-modal__panel" role="dialog" aria-modal="true" aria-labelledby="quick-title" tabindex="-1" x-init="$el.focus()">
        <header class="ui-modal__head">
            <div>
                <x-ui.eyebrow>{{ $material->category->name }}</x-ui.eyebrow>
                <h2 id="quick-title">{{ $material->name }}</h2>
                <p class="ui-modal__code"><code data-test="quick-code">{{ $material->code }}</code></p>
            </div>
            <button type="button" class="ui-modal__close" wire:click="closeQuick" aria-label="{{ __('Close') }}" data-test="quick-close">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
            </button>
        </header>

        <div class="ui-modal__body">
            <x-ui.swatch-preview
                class="ui-modal__preview"
                :card="$card"
                :targets="$targets"
                :tag="$material->category->code"
                :variants-count="$variantsCount"
                :name="$material->name"
                :open="true"
            />

            <dl class="ui-modal__facts">
                <div>
                    <dt>{{ __('Colourway') }}</dt>
                    <dd>
                        <span x-text="chosen?.name ?? '—'">{{ $card['variants'][$card['active']]['name'] ?? '—' }}</span>
                        <code x-text="chosen?.code ?? ''" data-test="quick-variant-code">{{ $card['variants'][$card['active']]['code'] ?? '' }}</code>
                    </dd>
                </div>
                <div>
                    <dt>{{ __('Supplier') }}</dt>
                    <dd>{{ $material->supplier?->name ?? __('In-house') }}@if ($material->supplier_product_code) <code>{{ $material->supplier_product_code }}</code>@endif</dd>
                </div>
                @if ($material->collection)
                    <div>
                        <dt>{{ __('Collection') }}</dt>
                        <dd>{{ $material->collection }}</dd>
                    </div>
                @endif
                @if ($material->tile_width_mm)
                    <div>
                        <dt>{{ __('Tile') }}</dt>
                        <dd>{{ (float) $material->tile_width_mm }} × {{ (float) $material->tile_height_mm }} mm</dd>
                    </div>
                @endif
                <div>
                    <dt>{{ __('Variants') }}</dt>
                    <dd>{{ $variantsCount }}</dd>
                </div>
                <div>
                    <dt>{{ __('State') }}</dt>
                    <dd class="ui-modal__badges">
                        <x-ui.badge :tone="$status" dot>{{ $material->status->label() }}</x-ui.badge>
                        <x-ui.badge tone="info">{{ $material->currentVersion ? 'v'.$material->currentVersion->number : __('Unpublished') }}</x-ui.badge>
                        @if ($material->visibility->value === 'restricted')
                            <x-ui.badge tone="warning">{{ __('Restricted') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <footer class="ui-modal__foot">
            @if ($command)
                <p class="ui-revit__status" data-status="{{ $command->status->value }}" data-test="quick-command">
                    <span class="ui-status-light ui-status-light--{{ $command->status->value }}" aria-hidden="true"></span>
                    <strong>{{ $command->payload['variant'] ?? '' }}</strong>
                    <span>→ {{ $command->session->label() }}</span>
                    <span>· {{ $command->status->label() }}</span>
                    @if ($command->message)<span>· {{ \Illuminate\Support\Str::limit($command->message, 120) }}</span>@endif
                </p>
            @endif

            <div class="ui-modal__actions">
                <a class="ui-button ui-button--quiet ui-button--md" href="{{ route('materials.show', $material) }}" wire:navigate data-test="quick-open-record">{{ __('Full record') }}</a>

                <button
                    type="button"
                    class="ui-button ui-button--primary ui-button--md ui-modal__apply"
                    x-on:click="$wire.applyInRevit(chosen.id)"
                    @disabled($blocked !== null)
                    data-test="quick-apply"
                >
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h9M10 6l4 4-4 4" /><path d="M16 4v12" /></svg>
                    <span>{{ __('Apply in Revit') }}</span>
                    <em x-text="chosen?.name ?? ''">{{ $card['variants'][$card['active']]['name'] ?? '' }}</em>
                </button>
            </div>

            @if ($blocked)
                <p class="ui-modal__note" data-test="quick-blocked">{{ $blocked }}</p>
            @elseif ($sessions->count() > 1)
                <label class="ui-modal__note">
                    {{ __('Send to') }}
                    <select class="ui-select ui-select--sm" wire:model.live="revitSessionId" aria-label="{{ __('Revit session') }}">
                        @foreach ($sessions as $session)
                            <option value="{{ $session->id }}">{{ $session->label() }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <p class="ui-modal__note">{{ __('Sends to :session', ['session' => $sessions->first()->label()]) }}</p>
            @endif
        </footer>
    </div>
</div>
