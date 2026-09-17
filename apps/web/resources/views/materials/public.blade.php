<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="color-scheme" content="light" />
        <meta name="robots" content="noindex, nofollow, noarchive" />
        <meta name="referrer" content="no-referrer" />
        <title>{{ $material->name }} · {{ config('app.name', 'Olsyn Asset Library') }}</title>
        <meta name="description" content="{{ $variant ? $variant->name.' · ' : '' }}{{ $material->category->name }} material by {{ $material->supplier?->name ?? 'Olsyn' }}" />
        @if ($previewUrl)<meta property="og:image" content="{{ $previewUrl }}" />@endif
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="ui-page ui-public-material" data-ui>
        <div class="ui-grain" aria-hidden="true"></div>

        <div class="ui-public-material__frame">
            <header class="ui-public-material__nav">
                <x-ui.brand :href="route('home')" />
                <span>{{ __('Shared material record') }}</span>
            </header>

            <main class="ui-public-material__card" data-test="public-material">
                <div class="ui-public-material__visual" style="--material-colour: {{ $fallbackHex }}">
                    @if ($previewUrl)
                        <img src="{{ $previewUrl }}" alt="{{ $variant ? __('Rendered preview of :material in :variant', ['material' => $material->name, 'variant' => $variant->name]) : __('Rendered preview of :material', ['material' => $material->name]) }}" />
                    @else
                        <div class="ui-public-material__fallback" aria-hidden="true"></div>
                    @endif
                    <span>{{ $material->category->code }}</span>
                </div>

                <article class="ui-public-material__content">
                    <x-ui.eyebrow>{{ $material->category->name }}</x-ui.eyebrow>
                    <h1>{{ $material->name }}</h1>
                    <code>{{ $material->code }}</code>

                    @if ($variant)
                        <div class="ui-public-material__colourway">
                            <i style="--chip: {{ $fallbackHex }}" aria-hidden="true"></i>
                            <div>
                                <small>{{ __('Colourway') }}</small>
                                <strong>{{ $variant->name }}</strong>
                                <code>{{ $variant->code }}</code>
                            </div>
                        </div>
                    @endif

                    <dl class="ui-public-material__facts">
                        <div>
                            <dt>{{ __('Supplier') }}</dt>
                            <dd>{{ $material->supplier?->name ?? __('In-house') }}</dd>
                        </div>
                        @if ($material->supplier_product_code)
                            <div>
                                <dt>{{ __('Product code') }}</dt>
                                <dd>{{ $material->supplier_product_code }}</dd>
                            </div>
                        @endif
                        @if ($material->collection)
                            <div>
                                <dt>{{ __('Collection') }}</dt>
                                <dd>{{ $material->collection }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>{{ __('Colourways') }}</dt>
                            <dd>{{ $variantsCount }}</dd>
                        </div>
                        @if ($variant?->effectiveTileWidthMm())
                            <div>
                                <dt>{{ __('Format') }}</dt>
                                <dd>{{ (float) $variant->effectiveTileWidthMm() }} × {{ (float) $variant->effectiveTileHeightMm() }} mm</dd>
                            </div>
                        @endif
                        @if ($variant?->thickness_mm ?? $material->thickness_mm)
                            <div>
                                <dt>{{ __('Thickness') }}</dt>
                                <dd>{{ (float) ($variant?->thickness_mm ?? $material->thickness_mm) }} mm</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($material->description)
                        <p class="ui-public-material__description">{{ $material->description }}</p>
                    @endif
                </article>
            </main>

            <footer class="ui-public-material__foot">
                <span>{{ __('Material information shared from OPAL') }}</span>
                <a href="{{ route('login') }}">{{ __('Sign in to OPAL') }}</a>
            </footer>
        </div>
    </body>
</html>
