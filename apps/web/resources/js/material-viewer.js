const preferredShapes = new Map();
let stageModule;
const loadMaterialStage = () => stageModule ??= import('./material-stage').catch((error) => {
    stageModule = null;
    throw error;
});

export function materialViewer(config, loadStage = loadMaterialStage) {
    let stage = null;
    let attached = false;
    let disposed = false;
    let observer = null;
    return {
        sets: config.sets ?? {},
        objectSizeMm: config.objectSizeMm ?? 1000,
        shapeKey: config.shapeKey ?? null,
        shape: (config.shapeKey ? preferredShapes.get(config.shapeKey) : null) ?? config.shape ?? 'ball',
        framing: config.framing ?? 1.08,
        verticalBias: config.verticalBias ?? 0,
        zoom: config.zoom !== false,
        autoRotate: config.autoRotate !== false,
        showRevision: 0,
        status: 'idle',
        started: false,
        host: null,
        variantId: null,
        view: 'surface',
        mapRole: null,

        get available() {
            return Object.keys(this.sets).length > 0;
        },

        get currentSet() {
            return this.sets[this.variantId] ?? Object.values(this.sets)[0] ?? null;
        },

        get maps() {
            const set = this.currentSet;

            if (! set) {
                return [];
            }

            const roles = [
                ['base_color', 'Base colour', 'Colour'],
                ['normal', 'Normal', 'Normal'],
                ['roughness', 'Roughness', 'Roughness'],
                ['metallic', 'Metallic', 'Metallic'],
                ['ao', 'Ambient occlusion', 'AO'],
                ['height', 'Height', 'Height'],
                ['bump', 'Bump', 'Bump'],
                ['emissive', 'Emissive', 'Emit'],
                ['opacity', 'Opacity', 'Opacity'],
            ];

            return roles
                .filter(([role]) => typeof set[role] === 'string' && set[role] !== '')
                .map(([role, label, shortLabel]) => ({ role, label, shortLabel, url: set[role] }));
        },

        get tileAspect() {
            const set = this.currentSet;
            return Math.max(0.01, (set?.tile_mm || 1000) / (set?.tile_height_mm || set?.tile_mm || 1000));
        },

        get activeMap() {
            return this.maps.find((map) => map.role === this.mapRole) ?? null;
        },

        hasMap(role) {
            return this.maps.some((map) => map.role === role);
        },

        mapUrl(role) {
            return this.maps.find((map) => map.role === role)?.url ?? '';
        },

        init() {
            this.host = this.$refs.stage ?? this.$el;
            this.$watch('shape', (value) => {
                if (attached) stage.setShape(value);
                if (this.shapeKey) preferredShapes.set(this.shapeKey, value);
            });
            if (! this.available || config.autoStart === false) return;

            // Detail pages can be browsed before their offscreen inspector starts.
            observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    this.start();
                }
            });
            observer.observe(this.host);
        },

        async start() {
            if (disposed || this.started) return;
            this.started = true;
            this.status = 'loading';
            try {
                ({ stage } = await loadStage());
                if (disposed || ! this.host.isConnected) return;
                await stage.attach(this.host, {
                    zoom: this.zoom, framing: this.framing, verticalBias: this.verticalBias,
                    autoRotate: this.autoRotate, isCurrent: () => ! disposed,
                });
                if (disposed || ! this.host.isConnected) return;
                attached = true;
                stage.setShape(this.shape);
                await this.show(this.variantId);
            } catch (error) {
                if (! disposed) {
                    this.status = 'error';
                    this.started = false;
                }
                console.warn('Material preview could not start', error);
            }
        },

        destroy() {
            disposed = true;
            this.showRevision++;
            observer?.disconnect();
            stage?.detach(this.host);
        },

        /** Show a variant's maps; falls back to the first set on record. */
        async show(variantId) {
            const set = this.sets[variantId] ?? Object.values(this.sets)[0];

            if (! set || ! this.available) {
                return;
            }

            this.variantId = variantId;
            if (! attached || disposed) return;

            if ((this.view === 'map' || this.view === 'tile') && ! this.activeMap) {
                this.inspectSurface();
            }

            this.status = 'loading';
            const revision = ++this.showRevision;
            try {
                await stage.show(set, this.objectSizeMm);
                if (! disposed && revision === this.showRevision) this.status = 'ready';
            } catch (error) {
                if (! disposed && revision === this.showRevision) {
                    this.status = 'error';
                    this.started = false;
                }
                console.warn('Material maps could not load', error);
            }
        },

        replacePreview({ set, size }) {
            this.sets = { preview: set };
            this.objectSizeMm = size;
            this.show('preview');
        },

        toggleRotation() {
            this.autoRotate = ! this.autoRotate;
            if (! attached) return;
            stage.autoRotate = this.autoRotate;
            if (stage.gl) stage.gl.controls.autoRotate = this.autoRotate;
        },

        resetView() {
            if (attached) stage.frame_();
        },

        inspectSurface() {
            this.view = 'surface';
            this.mapRole = null;
        },

        inspectMap(role) {
            if (! this.maps.some((map) => map.role === role)) {
                return;
            }

            this.mapRole = role;
            this.view = 'map';
        },

        inspectTile() {
            if (! this.activeMap) {
                this.mapRole = this.maps.find((map) => map.role === 'base_color')?.role ?? this.maps[0]?.role ?? null;
            }

            if (this.activeMap) {
                this.view = 'tile';
            }
        },
    };
}
