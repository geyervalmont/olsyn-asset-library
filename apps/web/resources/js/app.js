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
 * A three.js inspector for a material's canonical PBR maps under an
 * image-based studio light. Follows the colourway picked on the page.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('materialViewer', (config) => {
        // three.js objects must stay out of Alpine's reactive proxy.
        const gl = { scene: null, renderer: null, camera: null, controls: null, mesh: null, loader: null, frame: null };

        return {
            sets: config.sets ?? {},
            shape: 'sphere',
            objectSizeMm: config.objectSizeMm ?? 1000,
            loadedFor: null,
            status: 'idle',

            get available() {
                return Object.keys(this.sets).length > 0;
            },

            init() {
                if (! this.available || gl.renderer) {
                    return;
                }

                const canvas = this.$refs.canvas;
                const width = canvas.clientWidth || 640;
                const height = canvas.clientHeight || 400;

                gl.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
                gl.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
                gl.renderer.setSize(width, height, false);
                gl.renderer.toneMapping = THREE.ACESFilmicToneMapping;
                gl.renderer.toneMappingExposure = 1.0;
                gl.renderer.outputColorSpace = THREE.SRGBColorSpace;

                gl.scene = new THREE.Scene();
                const pmrem = new THREE.PMREMGenerator(gl.renderer);
                gl.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;

                gl.camera = new THREE.PerspectiveCamera(32, width / height, 0.1, 100);
                gl.camera.position.set(0, 0.8, 3.2);

                gl.controls = new OrbitControls(gl.camera, canvas);
                gl.controls.enableDamping = true;
                gl.controls.enablePan = false;
                gl.controls.minDistance = 1.6;
                gl.controls.maxDistance = 6;

                const key = new THREE.DirectionalLight(0xfff4e6, 0.6);
                key.position.set(2, 3, 2);
                gl.scene.add(key);

                gl.loader = new THREE.TextureLoader();
                this.rebuild();

                new ResizeObserver(() => {
                    const w = canvas.clientWidth || width;
                    const h = canvas.clientHeight || height;
                    gl.renderer.setSize(w, h, false);
                    gl.camera.aspect = w / h;
                    gl.camera.updateProjectionMatrix();
                }).observe(canvas);

                const animate = () => {
                    gl.frame = requestAnimationFrame(animate);
                    gl.controls.update();
                    gl.renderer.render(gl.scene, gl.camera);
                };
                animate();

                this.$watch('shape', () => this.rebuild());
                this.show(null);
            },

            destroy() {
                if (gl.frame) {
                    cancelAnimationFrame(gl.frame);
                }
                gl.renderer?.dispose();
            },

            geometry() {
                switch (this.shape) {
                    case 'plane':
                        return new THREE.PlaneGeometry(2.2, 2.2, 64, 64);
                    case 'cube':
                        return new THREE.BoxGeometry(1.5, 1.5, 1.5, 32, 32, 32);
                    default:
                        return new THREE.SphereGeometry(1, 96, 96);
                }
            },

            rebuild() {
                if (gl.mesh) {
                    gl.scene.remove(gl.mesh);
                    gl.mesh.geometry.dispose();
                }

                const material = gl.mesh?.material ?? new THREE.MeshPhysicalMaterial({ color: 0xcfcbc1, roughness: 0.8, metalness: 0 });
                gl.mesh = new THREE.Mesh(this.geometry(), material);
                gl.scene.add(gl.mesh);
            },

            /**
             * Load the maps of the given variant id; falls back to the first set.
             */
            show(variantId) {
                const set = this.sets[variantId] ?? Object.values(this.sets)[0];

                if (! set || ! gl.mesh || this.loadedFor === set.key) {
                    return;
                }

                this.loadedFor = set.key;
                this.status = 'loading';

                const material = gl.mesh.material;
                const repeat = Math.max(1, this.objectSizeMm / Math.max(set.tile_mm || 1000, 1));
                const texture = (url, colorSpace) => {
                    if (! url) {
                        return null;
                    }
                    const map = gl.loader.load(url, () => { this.status = 'ready'; });
                    map.wrapS = map.wrapT = THREE.RepeatWrapping;
                    map.repeat.set(repeat, repeat);
                    map.colorSpace = colorSpace;
                    map.anisotropy = 8;
                    return map;
                };

                material.map = texture(set.base_color, THREE.SRGBColorSpace);
                material.normalMap = texture(set.normal, THREE.NoColorSpace);
                material.roughnessMap = texture(set.roughness, THREE.NoColorSpace);
                material.metalnessMap = texture(set.metallic, THREE.NoColorSpace);
                material.aoMap = texture(set.ao, THREE.NoColorSpace);
                material.displacementMap = null;
                material.color.set(set.base_color ? 0xffffff : (set.hex || '#cfcbc1'));
                material.roughness = set.roughness ? 1 : 0.75;
                material.metalness = set.metallic ? 1 : 0;
                material.normalScale.set(1, 1);
                material.needsUpdate = true;

                if (! set.base_color && ! set.normal) {
                    this.status = 'ready';
                }
            },
        };
    });
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
