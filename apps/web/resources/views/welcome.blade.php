<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="color-scheme" content="light" />
        <title>{{ config('app.name', 'Olsyn Asset Library') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="ui-page ui-home" data-ui>
        <div class="ui-grain" aria-hidden="true"></div>

        <div class="ui-home__frame">
            <header class="ui-home__nav">
                <x-ui.brand href="{{ route('home') }}" />
                <nav aria-label="Primary">
                    @if (Route::has('ui.index'))
                        <a href="{{ route('ui.index') }}">UI workbench</a>
                    @endif
                    @auth
                        <x-ui.button href="{{ route('dashboard') }}" variant="secondary" size="sm">Open dashboard</x-ui.button>
                    @else
                        <x-ui.button href="{{ route('login') }}" variant="secondary" size="sm">Sign in</x-ui.button>
                    @endauth
                </nav>
            </header>

            <main class="ui-home__main">
                <section class="ui-hero">
                    <div class="ui-hero__copy">
                        <x-ui.eyebrow>Internal tool · material production</x-ui.eyebrow>
                        <h1>Materials, with <em>provenance.</em></h1>
                        <p class="ui-hero__lede">
                            Catalogue supplier materials, track their files and approvals, and
                            publish approved sets to a read-only path that Revit can use. The
                            database owns the truth; the filesystem is a view.
                        </p>
                        <div class="ui-hero__actions">
                            @auth
                                <x-ui.button href="{{ route('dashboard') }}" size="lg">
                                    Open dashboard
                                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7 4 6 6-6 6" /></svg>
                                </x-ui.button>
                            @else
                                <x-ui.button href="{{ route('login') }}" size="lg">
                                    Sign in
                                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7 4 6 6-6 6" /></svg>
                                </x-ui.button>
                                @if (Route::has('register'))
                                    <x-ui.button href="{{ route('register') }}" variant="secondary" size="lg">Create an account</x-ui.button>
                                @endif
                            @endauth
                        </div>
                    </div>

                    <div class="ui-instrument">
                        <header class="ui-instrument__bar">
                            <span>Namespace</span>
                            <strong>\\materials.olsyn.test</strong>
                            <small>PRISMFS</small>
                        </header>
                        <div class="ui-instrument__body">
                            <p>// read-only projection</p>
<pre class="ui-tree">\\materials.olsyn.test
├── Stone
│   └── Travertine, honed
│       ├── <b>Travertine_Honed.rvt</b>
│       └── Textures
│           ├── base-color.jpg
│           └── normal.png
└── Timber
    └── Blackbutt, clear         <i>approved · rev 04</i></pre>
                        </div>
                    </div>
                </section>

                <div class="ui-home__grid">
                    <x-ui.panel class="ui-home-card">
                        <i>01 / Catalogue</i>
                        <strong>One record per material</strong>
                        <p>Supplier, finish, colourway, dimensions and source files live on the record, not in a filename.</p>
                    </x-ui.panel>
                    <x-ui.panel class="ui-home-card">
                        <i>02 / Review</i>
                        <strong>Candidates, then approval</strong>
                        <p>Processing produces candidates with evidence. A person decides what enters the library.</p>
                    </x-ui.panel>
                    <x-ui.panel class="ui-home-card">
                        <i>03 / Publish</i>
                        <strong>A stable path for Revit</strong>
                        <p>Approved sets appear on a read-only share with ordinary paths. Renames don't break links.</p>
                    </x-ui.panel>
                </div>
            </main>

            <footer class="ui-home__footer">
                <span>Olsyn Asset Library · internal</span>
                <span>Laravel · PostgreSQL · S3 storage · PrismFS · SMB</span>
            </footer>
        </div>
    </body>
</html>
