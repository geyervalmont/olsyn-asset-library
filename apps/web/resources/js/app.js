import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

/**
 * A plain click opens the material quick view; a modifier or middle click is
 * left alone so the full record still opens in a new tab.
 */
function quickOpen(component, event, code, variantId = null) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
        return;
    }

    event.preventDefault();
    component.$wire?.openQuick(code, variantId);
}

document.addEventListener('alpine:init', () => {
    /**
     * A material swatch: lifts and tilts toward the pointer, and previews
     * whichever colourway the pointer rests on.
     */
    window.Alpine.data('swatchCard', (config) => ({
        variants: config.variants ?? [],
        // The committed choice: what apply acts on, and what shows at rest.
        selected: config.active ?? 0,
        // A transient look at another colourway while the pointer is on its chip.
        hovered: null,
        overChips: false,
        tilt: '',
        hovering: false,
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

        /** What the preview shows: the hovered colourway, else the chosen one. */
        get current() {
            return this.variants[this.hovered ?? this.selected] ?? null;
        },

        /** The chosen colourway, whatever the pointer is doing. */
        get chosen() {
            return this.variants[this.selected] ?? null;
        },

        move(event) {
            // Tilting under the chips would slide them out from under the
            // pointer, so the card holds still while they are being used.
            if (this.reduced || this.overChips) {
                return;
            }

            const rect = this.$el.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width - 0.5;
            const y = (event.clientY - rect.top) / rect.height - 0.5;

            this.tilt = `perspective(1400px) rotateX(${(-y * 1.4).toFixed(2)}deg) rotateY(${(x * 1.8).toFixed(2)}deg) translateY(-1px) scale(1.006)`;
        },

        enter() {
            this.hovering = true;
        },

        leave() {
            this.hovering = false;
            this.overChips = false;
            this.tilt = '';
            this.hovered = null;
        },

        holdTilt(over) {
            this.overChips = over;

            if (over) {
                this.tilt = '';
            }
        },

        preview(index) {
            this.hovered = index;
        },

        clearPreview() {
            this.hovered = null;
        },

        pick(index) {
            this.selected = index;
            this.hovered = null;
        },

        quickOpen(event, code) {
            quickOpen(this, event, code, this.chosen?.id);
        },
    }));

    // Table rows want the quick view without the swatch behaviour.
    window.Alpine.data('quickLink', () => ({
        quickOpen(event, code) {
            quickOpen(this, event, code);
        },
    }));
});

/**
 * The material stage: one WebGL context for the whole app.
 *
 * A single canvas, shader ball and material move to whichever host is on
 * screen, so a library of thirty quick views costs one renderer, not thirty.
 * Pages swap texture maps; nothing else is rebuilt.
 */
const MODEL_URL = '/models/shader-ball.glb';
const CACHE_LIMIT = 16;

const stage = {
    gl: null,
    booting: null,
    host: null,
    sizeObserver: null,
    frame: null,
    visible: true,
    pending: null,
    shownKey: null,
    cache: new Map(),

    boot() {
        if (this.booting) {
            return this.booting;
        }

        this.booting = (async () => {
            const canvas = document.createElement('canvas');
            canvas.className = 'ui-stage__canvas';

            const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1;
            renderer.outputColorSpace = THREE.SRGBColorSpace;

            const scene = new THREE.Scene();
            const pmrem = new THREE.PMREMGenerator(renderer);
            scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;

            const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
            camera.position.set(0, 0.6, 3.4);

            const controls = new OrbitControls(camera, canvas);
            controls.enableDamping = true;
            controls.enablePan = false;
            controls.minDistance = 1.8;
            controls.maxDistance = 6;
            controls.autoRotate = ! window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            controls.autoRotateSpeed = 1.4;

            const key = new THREE.DirectionalLight(0xfff4e6, 0.5);
            key.position.set(2, 3, 2);
            scene.add(key);

            const material = new THREE.MeshPhysicalMaterial({ color: 0xcfcbc1, roughness: 0.8, metalness: 0 });
            const shapes = {
                ball: await loadShaderBall(material),
                plane: new THREE.Mesh(new THREE.PlaneGeometry(2.4, 2.4, 64, 64), material),
                cube: new THREE.Mesh(new THREE.BoxGeometry(1.6, 1.6, 1.6, 32, 32, 32), material),
            };

            scene.add(shapes.ball);

            this.gl = { canvas, renderer, scene, camera, controls, material, shapes, shown: shapes.ball, loader: new THREE.TextureLoader() };

            this.sizeObserver = new ResizeObserver(() => this.resize());
            this.sizeObserver.observe(canvas);
            document.addEventListener('visibilitychange', () => this.pace());

            return this.gl;
        })();

        return this.booting;
    },

    /** Move the canvas into a host element and start rendering. */
    async attach(host, options = {}) {
        const gl = await this.boot();

        this.host = host;
        host.appendChild(gl.canvas);
        gl.controls.enableZoom = options.zoom !== false;
        gl.controls.enableRotate = options.rotate !== false;

        if (! this.observer) {
            this.observer = new IntersectionObserver((entries) => {
                this.visible = entries.some((entry) => entry.isIntersecting);
                this.pace();
            }, { threshold: 0.05 });
        }

        this.observer.disconnect();
        this.observer.observe(host);
        this.sizeObserver.observe(host);
        this.resize();
        // The host may still be laying out (a sheet sliding up, a stylesheet
        // arriving); frame again once it has settled.
        requestAnimationFrame(() => this.resize());
        setTimeout(() => this.resize(), 300);
        this.pace();

        return gl;
    },

    detach(host) {
        if (this.host !== host) {
            return;
        }

        this.observer?.disconnect();
        this.sizeObserver?.unobserve(host);
        this.gl?.canvas.remove();
        this.host = null;
        this.pace();
    },

    resize() {
        const gl = this.gl;
        const host = this.host;

        if (! gl || ! host) {
            return;
        }

        const width = host.clientWidth || 640;
        const height = host.clientHeight || 360;

        gl.renderer.setSize(width, height, false);
        gl.camera.aspect = width / Math.max(height, 1);
        gl.camera.updateProjectionMatrix();
        this.frame_();
    },

    /** Pull the camera back until the shape fits, whatever the frame's shape. */
    frame_() {
        const gl = this.gl;

        if (! gl) {
            return;
        }

        const box = new THREE.Box3().setFromObject(gl.shown);

        if (box.isEmpty()) {
            return;
        }

        const size = box.getSize(new THREE.Vector3());
        const centre = box.getCentre ? box.getCentre(new THREE.Vector3()) : box.getCenter(new THREE.Vector3());
        const vertical = THREE.MathUtils.degToRad(gl.camera.fov);
        const horizontal = 2 * Math.atan(Math.tan(vertical / 2) * gl.camera.aspect);
        // Far enough that both the height and the width clear the frame.
        const distance = 1.08 * Math.max(
            (size.y / 2) / Math.tan(vertical / 2),
            (Math.max(size.x, size.z) / 2) / Math.tan(horizontal / 2),
        );

        gl.controls.target.copy(centre);
        gl.camera.position.set(centre.x, centre.y + size.y * 0.12, centre.z + distance);
        gl.controls.minDistance = distance * 0.5;
        gl.controls.maxDistance = distance * 2.4;
        gl.controls.update();
    },

    /** Render only while a host is on screen and the tab is in front. */
    pace() {
        const wanted = Boolean(this.host) && this.visible && ! document.hidden;

        if (wanted && this.frame === null) {
            const draw = () => {
                this.frame = requestAnimationFrame(draw);
                this.gl.controls.update();
                this.gl.renderer.render(this.gl.scene, this.gl.camera);
            };
            draw();
        }

        if (! wanted && this.frame !== null) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    },

    setShape(name) {
        const gl = this.gl;
        const next = gl?.shapes[name];

        if (! gl || ! next || next === gl.shown) {
            return;
        }

        gl.scene.remove(gl.shown);
        gl.scene.add(next);
        gl.shown = next;
        this.frame_();
    },

    /**
     * Show a variant's maps. Rapid changes (sweeping across colourway chips)
     * coalesce, and loaded maps are kept so going back is instant.
     */
    show(set, objectSizeMm = 1000) {
        clearTimeout(this.pending);

        return new Promise((resolve) => {
            this.pending = setTimeout(async () => {
                const gl = await this.boot();

                if (! set || this.shownKey === set.key) {
                    return resolve();
                }

                const maps = await this.maps(gl, set, objectSizeMm);
                const material = gl.material;

                material.map = maps.base_color ?? null;
                material.normalMap = maps.normal ?? null;
                material.roughnessMap = maps.roughness ?? null;
                material.metalnessMap = maps.metallic ?? null;
                material.aoMap = maps.ao ?? null;
                material.color.set(maps.base_color ? 0xffffff : (set.hex || '#cfcbc1'));
                material.roughness = maps.roughness ? 1 : 0.75;
                material.metalness = maps.metallic ? 1 : 0;
                material.needsUpdate = true;

                this.shownKey = set.key;
                resolve();
            }, 90);
        });
    },

    /** Textures for a set, loaded once and reused. */
    maps(gl, set, objectSizeMm) {
        if (this.cache.has(set.key)) {
            const hit = this.cache.get(set.key);
            this.cache.delete(set.key);
            this.cache.set(set.key, hit);

            return hit.maps;
        }

        const repeat = Math.max(1, objectSizeMm / Math.max(set.tile_mm || 1000, 1));
        const load = (url, colorSpace) => new Promise((resolve) => {
            if (! url) {
                return resolve(null);
            }

            gl.loader.load(url, (texture) => {
                texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
                texture.repeat.set(repeat, repeat);
                texture.colorSpace = colorSpace;
                texture.anisotropy = 8;
                resolve(texture);
            }, undefined, () => resolve(null));
        });

        const loading = Promise.all([
            load(set.base_color, THREE.SRGBColorSpace),
            load(set.normal, THREE.NoColorSpace),
            load(set.roughness, THREE.NoColorSpace),
            load(set.metallic, THREE.NoColorSpace),
            load(set.ao, THREE.NoColorSpace),
        ]).then(([base_color, normal, roughness, metallic, ao]) => ({ base_color, normal, roughness, metallic, ao }));

        this.cache.set(set.key, { maps: loading });

        while (this.cache.size > CACHE_LIMIT) {
            const [oldest, entry] = this.cache.entries().next().value;
            this.cache.delete(oldest);
            Promise.resolve(entry.maps).then((maps) => Object.values(maps).forEach((map) => map?.dispose()));
        }

        return loading;
    },
};

/**
 * The shader ball, centred and scaled to the stage. Lights that ship inside
 * the file are dropped: the room environment does the lighting.
 */
async function loadShaderBall(material) {
    const gltf = await new GLTFLoader().loadAsync(MODEL_URL);
    const group = new THREE.Group();
    const model = gltf.scene;

    model.traverse((object) => {
        if (object.isMesh) {
            object.material = material;

            if (! object.geometry.attributes.tangent) {
                object.geometry.computeTangents?.();
            }
        }
    });

    [...model.children].filter((child) => child.isLight).forEach((light) => light.removeFromParent());

    const box = new THREE.Box3().setFromObject(model);
    const size = box.getSize(new THREE.Vector3());
    const centre = box.getCenter(new THREE.Vector3());
    const scale = 2.2 / Math.max(size.x, size.y, size.z, 0.001);

    model.scale.setScalar(scale);
    model.position.sub(centre.multiplyScalar(scale));
    group.add(model);

    return group;
}

document.addEventListener('alpine:init', () => {
    /**
     * Binds a host element to the shared stage and follows a colourway.
     */
    window.Alpine.data('materialViewer', (config) => ({
        sets: config.sets ?? {},
        objectSizeMm: config.objectSizeMm ?? 1000,
        shape: config.shape ?? 'ball',
        zoom: config.zoom !== false,
        status: 'idle',
        host: null,

        get available() {
            return Object.keys(this.sets).length > 0;
        },

        init() {
            if (! this.available) {
                return;
            }

            this.status = 'loading';
            this.host = this.$refs.stage ?? this.$el;
            stage.attach(this.host, { zoom: this.zoom }).then(() => {
                this.status = 'ready';
            });

            this.$watch('shape', (value) => stage.setShape(value));
        },

        destroy() {
            stage.detach(this.host);
        },

        /** Show a variant's maps; falls back to the first set on record. */
        show(variantId) {
            const set = this.sets[variantId] ?? Object.values(this.sets)[0];

            if (! set || ! this.available) {
                return;
            }

            this.status = 'loading';
            stage.show(set, this.objectSizeMm).then(() => {
                this.status = 'ready';
            });
        },
    }));

    /**
     * Whether this person's Revit is connected. Seeded from the server, kept
     * current by session events, and aged out when heartbeats stop.
     */
    window.Alpine.data('revitStatus', (config) => ({
        userId: config.userId,
        sessions: (config.sessions ?? []).map((session) => ({ ...session, seenAt: Date.now() - (session.secondsAgo ?? 0) * 1000 })),
        liveSeconds: config.liveSeconds ?? 90,
        now: Date.now(),
        timer: null,

        init() {
            this.timer = setInterval(() => {
                this.now = Date.now();
            }, 10000);

            window.Echo?.private(`user.${this.userId}`).listen('.session.updated', (event) => this.merge(event));
        },

        destroy() {
            clearInterval(this.timer);
        },

        merge(event) {
            const rest = this.sessions.filter((session) => session.id !== event.id);
            this.sessions = event.live === false ? rest : [...rest, { ...event, seenAt: Date.now() }];
            this.now = Date.now();
        },

        get live() {
            return this.sessions.filter((session) => this.now - session.seenAt < this.liveSeconds * 1000);
        },

        get state() {
            if (this.live.length > 0) {
                return 'live';
            }

            return this.sessions.length > 0 ? 'stale' : 'off';
        },

        get label() {
            const [first] = this.live;

            if (! first) {
                return this.sessions.length > 0 ? 'Revit lost' : 'No Revit';
            }

            const extra = this.live.length > 1 ? ` +${this.live.length - 1}` : '';

            return `${first.machine ?? 'Revit'}${extra}`;
        },

        get title() {
            const [first] = this.live;

            if (! first) {
                return this.sessions.length > 0
                    ? 'Revit stopped sending heartbeats'
                    : 'No Revit connected. Open OPAL → Connect in Revit.';
            }

            return this.live.map((session) => [session.machine, session.document].filter(Boolean).join(' · ')).join('\n');
        },
    }));
});

import './echo';
