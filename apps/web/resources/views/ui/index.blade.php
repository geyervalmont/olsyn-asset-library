<x-ui.shell title="UI workbench">
    <section id="overview" class="ui-section">
        <div class="ui-hero">
            <div class="ui-hero__copy">
                <x-ui.eyebrow index="00">Interface study / 0.1</x-ui.eyebrow>
                <h1>A precise library, <em>on paper.</em></h1>
                <p class="ui-hero__lede">
                    Dense enough for serious asset work. Calm enough to leave open all day.
                    The application borrows a developer tool’s structure and an architect’s drawing table.
                </p>
                <div class="ui-hero__actions">
                    <x-ui.button href="#components" size="lg">
                        Inspect components
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7 4 6 6-6 6" /></svg>
                    </x-ui.button>
                    <x-ui.button href="#templates" variant="secondary" size="lg">View templates</x-ui.button>
                </div>
            </div>

            <div class="ui-instrument">
                <header class="ui-instrument__bar">
                    <span>Ingest pipeline</span>
                    <strong>material-set_024</strong>
                    <small>PRISMFS</small>
                </header>
                <div class="ui-instrument__body">
                    <p>// source files</p>
                    <div class="ui-file-list">
                        <div class="ui-file-row">
                            <span class="ui-file-row__icon">JPG</span>
                            <span class="ui-file-row__name"><strong>travertine-honed_albedo.jpg</strong><small>4096 × 4096 · 18.4 MB</small></span>
                            <small>READY</small>
                            <span class="ui-file-row__check" aria-hidden="true">✓</span>
                        </div>
                        <div class="ui-file-row">
                            <span class="ui-file-row__icon">RVT</span>
                            <span class="ui-file-row__name"><strong>olsyn-material-library.rvt</strong><small>Revit 2026 · 84.2 MB</small></span>
                            <small>INDEXED</small>
                            <span class="ui-file-row__check" aria-hidden="true">✓</span>
                        </div>
                        <div class="ui-file-row">
                            <span class="ui-file-row__icon">PNG</span>
                            <span class="ui-file-row__name"><strong>travertine-honed_normal.png</strong><small>4096 × 4096 · 23.1 MB</small></span>
                            <small>SCALING</small>
                            <span class="ui-file-row__check ui-file-row__check--wait" aria-hidden="true">·</span>
                        </div>
                    </div>
                    <p class="ui-instrument__summary">
                        <strong>2 verified</strong>
                        <span>· 1 running · 125.7 MB · checksums verified</span>
                        <span class="ui-caret" aria-hidden="true"></span>
                    </p>
                </div>
            </div>
        </div>

        <div class="ui-bento" style="margin-top: 14px;">
            <x-ui.panel class="ui-bento__wide">
                <div class="ui-panel__heading">
                    <div>
                        <h3>Material library</h3>
                        <p>Specimen data for the primary browse view.</p>
                    </div>
                    <x-ui.badge tone="info">186 records</x-ui.badge>
                </div>
                <div class="ui-library-preview">
                    <div class="ui-material-grid">
                        <article class="ui-material-card"><span class="ui-material-card__swatch ui-swatch--stone"></span><div class="ui-material-card__body"><strong>Travertine, honed</strong><small>Stone · ST-014</small></div></article>
                        <article class="ui-material-card"><span class="ui-material-card__swatch ui-swatch--timber"></span><div class="ui-material-card__body"><strong>Blackbutt, clear</strong><small>Timber · TM-021</small></div></article>
                        <article class="ui-material-card"><span class="ui-material-card__swatch ui-swatch--fabric"></span><div class="ui-material-card__body"><strong>Wool felt, dusk</strong><small>Textile · TX-008</small></div></article>
                    </div>
                    <div class="ui-stat-stack">
                        <x-ui.stat label="Published" value="142" detail="available through PrismFS" />
                        <x-ui.stat label="In review" value="12" trend="+4" detail="this week" />
                        <x-ui.stat label="Storage" value="48.6" detail="GB across 624 files" />
                    </div>
                </div>
            </x-ui.panel>

            <x-ui.panel tone="sage">
                <div class="ui-panel__heading">
                    <div><h3>Processing queue</h3><p>Derived files and previews.</p></div>
                    <x-ui.badge tone="success" dot>Healthy</x-ui.badge>
                </div>
                <div style="display: grid; gap: 17px;">
                    <x-ui.progress label="Preview renders" value="78" />
                    <x-ui.progress label="Revit extraction" value="42" />
                    <x-ui.progress label="Texture indexing" value="96" />
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading">
                    <div><h3>Recent activity</h3><p>Short, factual, attributable.</p></div>
                </div>
                <div class="ui-activity">
                    <div class="ui-activity__row"><span class="ui-activity__mark">AR</span><div><strong>Finish revised</strong><small>Travertine, honed</small></div><time>09:42</time></div>
                    <div class="ui-activity__row"><span class="ui-activity__mark">JM</span><div><strong>RVT approved</strong><small>Blackbutt, clear</small></div><time>08:17</time></div>
                    <div class="ui-activity__row"><span class="ui-activity__mark">SY</span><div><strong>Files replaced</strong><small>Wool felt, dusk</small></div><time>MON</time></div>
                </div>
            </x-ui.panel>

            <x-ui.panel tone="dark" class="ui-bento__wide">
                <div class="ui-panel__heading">
                    <div><h3>Storage projection</h3><p>S3 objects remain the source; the namespace stays legible.</p></div>
                    <x-ui.badge tone="info">PrismFS</x-ui.badge>
                </div>
                <pre style="margin: 0; overflow-x: auto; color: rgba(245, 243, 239, .76); font-family: var(--ui-font-mono); font-size: 11px; line-height: 1.9;"><code><span style="color: var(--ui-periwinkle-lit)">\\materials.olsyn.test</span>
├── Stone
│   └── Travertine, honed
│       ├── <span style="color: var(--ui-amber-soft)">Travertine_Honed.rvt</span>
│       └── Textures
└── Timber
    └── Blackbutt, clear</code></pre>
            </x-ui.panel>

            <x-ui.panel tone="amber">
                <div class="ui-panel__heading">
                    <div><h3>Review note</h3><p>Warmth marks human attention, not generic warning.</p></div>
                </div>
                <p style="margin: 0; color: var(--ui-slate); font-family: var(--ui-font-display); font-size: 23px; line-height: 1.25;">“Confirm the repeat dimensions against the supplier sheet.”</p>
                <p class="ui-code" style="margin: 18px 0 0; color: #8c672f;">ADDED BY A. REED · 10:06</p>
            </x-ui.panel>
        </div>
    </section>

    <section id="foundations" class="ui-section">
        <div class="ui-section-head">
            <div>
                <x-ui.eyebrow index="01">Foundations</x-ui.eyebrow>
                <h2>Six colours. Three voices.</h2>
            </div>
            <p>Paper holds the interface together. Indigo is reserved for instruments and storage views. Periwinkle carries interaction.</p>
        </div>

        <div class="ui-palette" aria-label="Interface colour palette">
            <div class="ui-colour ui-colour--paper"><strong>Paper</strong><small>#F5F3EF</small></div>
            <div class="ui-colour ui-colour--ink"><strong>Ink</strong><small>#4A4D5C</small></div>
            <div class="ui-colour ui-colour--blue"><strong>Periwinkle</strong><small>#6B8DB8</small></div>
            <div class="ui-colour ui-colour--indigo"><strong>Indigo</strong><small>#3E4A6B</small></div>
            <div class="ui-colour ui-colour--amber"><strong>Amber</strong><small>#F0A952</small></div>
            <div class="ui-colour ui-colour--sage"><strong>Sage</strong><small>#B8BFB2</small></div>
        </div>

        <div class="ui-type-grid">
            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>Type system</h3><p>Each face has one job.</p></div></div>
                <div class="ui-type-sample"><span>Display / Fraunces</span><p class="ui-type-display">Material records, clearly drawn.</p></div>
                <div class="ui-type-sample"><span>Interface / Instrument</span><p class="ui-type-interface">Blackbutt timber, clear grade</p></div>
                <div class="ui-type-sample"><span>Body / Instrument</span><p class="ui-type-body">Every record keeps its specification, files and review state together.</p></div>
                <div class="ui-type-sample"><span>Annotation / Plex Mono</span><p class="ui-type-mono">RVT · REV 04 · PUBLISHED</p></div>
            </x-ui.panel>

            <x-ui.panel tone="sage">
                <div class="ui-panel__heading"><div><h3>Geometry and material</h3><p>A 4px ladder with quiet elevation.</p></div></div>
                <div style="display: grid; gap: 14px;">
                    <div class="ui-notice"><span class="ui-notice__rule"></span><div><strong>Drawn edges first</strong><p>Controls use 4px corners; panels use 12px; cards stop at 16px.</p></div><x-ui.badge>04—16</x-ui.badge></div>
                    <div class="ui-notice"><span class="ui-notice__rule" style="background: var(--ui-sage-deep);"></span><div><strong>Elevation is earned</strong><p>A contact shadow and one ambient shadow. Hover changes the shadow, not position.</p></div><x-ui.badge tone="success">EL—01</x-ui.badge></div>
                    <div class="ui-notice"><span class="ui-notice__rule" style="background: var(--ui-amber);"></span><div><strong>Grain stays quiet</strong><p>The texture should disappear when attention moves to the content.</p></div><x-ui.badge tone="warning">04%</x-ui.badge></div>
                </div>
            </x-ui.panel>
        </div>
    </section>

    <section id="components" class="ui-section">
        <div class="ui-section-head">
            <div>
                <x-ui.eyebrow index="02">Components</x-ui.eyebrow>
                <h2>The working set.</h2>
            </div>
            <p>These are anonymous Blade components. The workbench exercises their states before they enter a product screen.</p>
        </div>

        <div class="ui-component-grid">
            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>Actions</h3><p>Five variants, three sizes.</p></div></div>
                <div class="ui-button-row">
                    <x-ui.button>Primary action</x-ui.button>
                    <x-ui.button variant="secondary">Secondary</x-ui.button>
                    <x-ui.button variant="quiet">Quiet</x-ui.button>
                </div>
                <div class="ui-button-row">
                    <x-ui.button variant="ghost" size="sm">Ghost action</x-ui.button>
                    <x-ui.button variant="danger" size="sm">Remove file</x-ui.button>
                    <x-ui.button disabled size="sm">Unavailable</x-ui.button>
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading">
                    <div><h3>Status and menus</h3><p>Labels stay rectangular and compact.</p></div>
                    <details class="ui-menu">
                        <summary><x-ui.button variant="quiet" size="sm">More ···</x-ui.button></summary>
                        <div class="ui-menu__content">
                            <a href="#components">Duplicate record</a>
                            <a href="#components">Export metadata</a>
                            <a href="#components">Archive material</a>
                        </div>
                    </details>
                </div>
                <div class="ui-badge-row">
                    <x-ui.badge dot>Draft</x-ui.badge>
                    <x-ui.badge tone="info" dot>In review</x-ui.badge>
                    <x-ui.badge tone="success" dot>Published</x-ui.badge>
                    <x-ui.badge tone="warning" dot>Attention</x-ui.badge>
                    <x-ui.badge tone="danger" dot>Failed</x-ui.badge>
                </div>
            </x-ui.panel>

            <x-ui.panel class="ui-component-grid__wide">
                <div class="ui-panel__heading"><div><h3>Fields</h3><p>Labels above, help below, units explicit.</p></div></div>
                <div class="ui-form-grid">
                    <x-ui.field label="Material name" for="material-name" hint="Used in schedules and exports." required>
                        <input id="material-name" class="ui-input" value="Travertine, honed" />
                    </x-ui.field>
                    <x-ui.field label="Category" for="material-category">
                        <select id="material-category" class="ui-select"><option>Stone</option><option>Timber</option><option>Textile</option></select>
                    </x-ui.field>
                    <x-ui.field label="Reference" for="material-reference" error="That reference is already in use.">
                        <input id="material-reference" class="ui-input" value="ST-014" aria-invalid="true" />
                    </x-ui.field>
                    <x-ui.field label="Manufacturer" for="material-maker" hint="Optional supplier information.">
                        <input id="material-maker" class="ui-input" placeholder="Search manufacturers" />
                    </x-ui.field>
                </div>
            </x-ui.panel>

            <x-ui.panel tone="sage" class="ui-component-grid__narrow">
                <div class="ui-panel__heading"><div><h3>Preferences</h3><p>Switches are small controls, not pills.</p></div></div>
                <div class="ui-toggle-row"><div><strong>Include in PrismFS</strong><small>Publish approved representations to the filesystem.</small></div><label class="ui-toggle"><input type="checkbox" checked /><span></span></label></div>
                <div class="ui-toggle-row"><div><strong>Generate preview</strong><small>Create a browser thumbnail after upload.</small></div><label class="ui-toggle"><input type="checkbox" checked /><span></span></label></div>
                <div class="ui-toggle-row"><div><strong>Notify reviewers</strong><small>Send one notification when the set is ready.</small></div><label class="ui-toggle"><input type="checkbox" /><span></span></label></div>
            </x-ui.panel>

            <x-ui.panel class="ui-component-grid__wide">
                <div class="ui-panel__heading"><div><h3>Feedback</h3><p>Information, progress and an honest empty state.</p></div></div>
                <div style="display: grid; gap: 14px;">
                    <div class="ui-notice"><span class="ui-notice__rule"></span><div><strong>Three files are ready to publish</strong><p>The Revit family and both texture maps passed validation.</p></div><x-ui.button variant="ghost" size="sm">Review</x-ui.button></div>
                    <x-ui.progress label="Uploading olsyn-material-library.rvt" value="64" />
                </div>
            </x-ui.panel>

            <x-ui.panel :padding="false" class="ui-component-grid__narrow">
                <x-ui.empty-state title="No representations yet" description="Add the first file or link an existing object from storage.">
                    <x-slot:action><x-ui.button size="sm">Add representation</x-ui.button></x-slot:action>
                </x-ui.empty-state>
            </x-ui.panel>
        </div>
    </section>

    <section id="data" class="ui-section">
        <div class="ui-section-head">
            <div>
                <x-ui.eyebrow index="03">Data patterns</x-ui.eyebrow>
                <h2>Records before decoration.</h2>
            </div>
            <p>A dense table is allowed to be a table. Texture and hierarchy sit around the data instead of competing with it.</p>
        </div>

        <div class="ui-data-shell">
            <div class="ui-data-toolbar">
                <label class="ui-search">
                    <span class="sr-only">Search materials</span>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" /><path d="m13 13 4 4" /></svg>
                    <input class="ui-input" placeholder="Search materials, tags or references" />
                </label>
                <x-ui.button variant="quiet" size="sm">Filter</x-ui.button>
                <x-ui.button size="sm">Add material</x-ui.button>
                <span class="ui-data-toolbar__count">4 OF 186 RECORDS</span>
            </div>
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead><tr><th>Material</th><th>Category</th><th>Representations</th><th>Updated</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr><td><div class="ui-table__material"><span class="ui-table__swatch ui-swatch--stone"></span><div><strong>Travertine, honed</strong><small>ST-014</small></div></div></td><td>Stone</td><td>RVT · JPG · PNG</td><td>Today, 09:42</td><td><x-ui.badge tone="success" dot>Published</x-ui.badge></td></tr>
                        <tr><td><div class="ui-table__material"><span class="ui-table__swatch ui-swatch--timber"></span><div><strong>Blackbutt, clear</strong><small>TM-021</small></div></div></td><td>Timber</td><td>RVT · JPG</td><td>Today, 08:17</td><td><x-ui.badge tone="success" dot>Published</x-ui.badge></td></tr>
                        <tr><td><div class="ui-table__material"><span class="ui-table__swatch ui-swatch--fabric"></span><div><strong>Wool felt, dusk</strong><small>TX-008</small></div></div></td><td>Textile</td><td>JPG · PNG</td><td>Monday</td><td><x-ui.badge tone="info" dot>In review</x-ui.badge></td></tr>
                        <tr><td><div class="ui-table__material"><span class="ui-table__swatch ui-swatch--metal"></span><div><strong>Stainless steel, brushed</strong><small>MT-003</small></div></div></td><td>Metal</td><td>RVT · JPG · MAT</td><td>28 Aug</td><td><x-ui.badge dot>Draft</x-ui.badge></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="templates" class="ui-section">
        <div class="ui-section-head">
            <div>
                <x-ui.eyebrow index="04">Templates</x-ui.eyebrow>
                <h2>Three base screens.</h2>
            </div>
            <p>The shell stays fixed while the working surface changes: browse, edit and review.</p>
        </div>

        <div class="ui-template-grid">
            <x-ui.template-frame title="Library index" meta="Browse">
                <div class="ui-mini-shell">
                    <div class="ui-mini-head"><div><small>Materials / All</small><strong>Library</strong></div><span class="ui-mini-action">+</span></div>
                    <div class="ui-mini-filter">Search 186 materials</div>
                    <div class="ui-mini-cards"><div class="ui-mini-card"><i></i><span>Travertine, honed</span></div><div class="ui-mini-card"><i></i><span>Blackbutt, clear</span></div><div class="ui-mini-card"><i></i><span>Wool felt, dusk</span></div><div class="ui-mini-card"><i></i><span>Brushed steel</span></div></div>
                </div>
            </x-ui.template-frame>

            <x-ui.template-frame title="Material record" meta="Edit">
                <div class="ui-mini-shell">
                    <div class="ui-mini-head"><div><small>Stone / ST-014</small><strong>Travertine, honed</strong></div><x-ui.badge tone="success">Published</x-ui.badge></div>
                    <div class="ui-mini-record"><div class="ui-mini-hero-swatch"></div><div class="ui-mini-fields"><p><span>Display name</span><i></i></p><p><span>Category</span><i></i></p><p><span>Finish</span><i></i></p><p><span>Supplier</span><i></i></p></div></div>
                </div>
            </x-ui.template-frame>

            <x-ui.template-frame title="Review queue" meta="Approve">
                <div class="ui-mini-shell">
                    <div class="ui-mini-head"><div><small>Workflow / Review</small><strong>12 waiting</strong></div><x-ui.badge tone="warning">3 due</x-ui.badge></div>
                    <div class="ui-mini-queue"><div class="ui-mini-queue__row"><i></i><div><strong>Limestone, sandblasted</strong><span></span></div><small>NEW</small></div><div class="ui-mini-queue__row"><i></i><div><strong>Acoustic panel, ochre</strong><span></span></div><small>REV 2</small></div><div class="ui-mini-queue__row"><i></i><div><strong>Carpet tile, blue grey</strong><span></span></div><small>FILES</small></div></div>
                </div>
            </x-ui.template-frame>
        </div>
    </section>
</x-ui.shell>
