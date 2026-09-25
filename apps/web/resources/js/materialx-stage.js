import * as THREE from 'three/webgpu';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { HDRLoader } from 'three/addons/loaders/HDRLoader.js';
import { MaterialXLoader } from 'three/addons/loaders/MaterialXLoader.js';
import { createStrictInterfaceValidator } from 'three/addons/loaders/materialx/MaterialXInterfaceValidation.js';

// One shared, on-demand node renderer. Three selects WebGPU when available and
// uses its WebGL2 backend otherwise; MaterialX still runs as a node graph.
export const stage = {
    host: null, gl: null, booting: null, request: 0, autoRotate: true,
    async boot() {
        return this.booting ??= (async () => {
            const renderer = new THREE.WebGPURenderer({ antialias: true, alpha: false });
            renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            await renderer.init();
            const scene = new THREE.Scene();
            scene.background = new THREE.Color('#dddcd6');
            try {
                const hdr = await new HDRLoader().loadAsync('/hdri/studio.hdr');
                hdr.mapping = THREE.EquirectangularReflectionMapping;
                scene.environment = hdr;
                scene.background = hdr;
                scene.backgroundBlurriness = 0.85;
                scene.backgroundIntensity = 0.5;
            } catch {
                scene.add(new THREE.HemisphereLight(0xffffff, 0x625d55, 2));
                const light = new THREE.DirectionalLight(0xffffff, 3);
                light.position.set(3, 4, 3); scene.add(light);
            }
            const camera = new THREE.PerspectiveCamera(32, 1, 0.01, 100);
            const controls = new OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true; controls.enablePan = false;
            controls.autoRotateSpeed = 1.4;
            const material = new THREE.MeshPhysicalNodeMaterial({ color: '#cfcbc1', roughness: 0.7 });
            const shapes = {
                ball: new THREE.Mesh(new THREE.SphereGeometry(1, 96, 64), material),
                sphere: new THREE.Mesh(new THREE.SphereGeometry(1, 96, 64), material),
                shader: new THREE.Mesh(new THREE.TorusKnotGeometry(0.65, 0.24, 160, 32), material),
                panel: new THREE.Mesh(new THREE.BoxGeometry(2, 2, 0.05), material),
                cube: new THREE.Mesh(new THREE.BoxGeometry(1.6, 1.6, 1.6), material),
            };
            for (const mesh of Object.values(shapes)) { mesh.geometry.computeTangents(); mesh.geometry.computeBoundingSphere(); }
            scene.add(shapes.ball);
            this.gl = { renderer, canvas: renderer.domElement, scene, camera, controls, material, shapes, shown: shapes.ball };
            this.sizeObserver = new ResizeObserver(() => this.resize());
            this.visibilityObserver = new IntersectionObserver((entries) => { this.visible = entries.some((entry) => entry.isIntersecting); });
            renderer.setAnimationLoop(() => {
                if (!this.host || !this.visible || document.hidden) return;
                controls.update(); renderer.render(scene, camera);
            });
            return this.gl;
        })().catch((error) => { this.booting = null; throw error; });
    },
    async attach(host, options = {}) {
        const gl = await this.boot();
        if (!host.isConnected || options.isCurrent?.() === false) return;
        if (this.host) this.sizeObserver.unobserve(this.host);
        this.host = host; this.framing = options.framing ?? 1.08;
        this.autoRotate = options.autoRotate !== false;
        gl.controls.autoRotate = this.autoRotate && !matchMedia('(prefers-reduced-motion: reduce)').matches;
        gl.controls.enableZoom = options.zoom !== false;
        host.appendChild(gl.canvas);
        this.sizeObserver.observe(host);
        this.visibilityObserver.disconnect(); this.visibilityObserver.observe(host);
        this.resize();
    },
    detach(host) {
        if (host !== this.host) return;
        this.request++; this.abort?.abort();
        this.sizeObserver.unobserve(host); this.visibilityObserver.disconnect();
        this.gl.canvas.remove(); this.host = null;
    },
    resize() {
        if (!this.host || !this.gl) return;
        const { renderer, camera } = this.gl;
        const width = this.host.clientWidth || 640, height = this.host.clientHeight || 360;
        renderer.setSize(width, height, false); camera.aspect = width / height;
        camera.updateProjectionMatrix(); this.frame_();
    },
    frame_() {
        if (!this.gl) return;
        const { camera, controls, shown } = this.gl;
        const radius = shown.geometry.boundingSphere.radius;
        const angle = Math.min(THREE.MathUtils.degToRad(camera.fov / 2), Math.atan(Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.aspect));
        const distance = radius / Math.sin(angle) * (this.framing ?? 1.08);
        camera.position.set(0, distance * 0.12, distance); controls.target.set(0, 0, 0);
        controls.minDistance = distance * 0.2; controls.maxDistance = distance * 3; controls.update();
    },
    setShape(name) {
        if (!this.gl?.shapes[name] || this.gl.shown === this.gl.shapes[name]) return;
        this.gl.scene.remove(this.gl.shown); this.gl.shown = this.gl.shapes[name];
        this.gl.scene.add(this.gl.shown); this.frame_();
    },
    async show(set, objectSizeMm, prepared = {}) {
        const request = ++this.request;
        this.abort?.abort(); this.abort = new AbortController();
        const bundle = prepared.bundle ? await prepared.bundle : await fetch(set.materialx_url, {
            signal: this.abort.signal, credentials: 'same-origin', headers: { Accept: 'application/json' },
        }).then((response) => {
            if (!response.ok) throw new Error('The package MaterialX preview is unavailable.');
            return response.json();
        });
        if (bundle.schema !== 'usd-toolbox.materialx-preview.v1' || !bundle.material_names?.length) throw new Error('Invalid MaterialX preview bundle.');
        if (request !== this.request) return;
        const textures = new Set();
        const bitmaps = new Map();
        const manager = new THREE.LoadingManager();
        // Decode before graph translation. The upstream loader throws from its
        // asynchronous image-error callback, which otherwise leaves a failed
        // image waiting indefinitely and prevents the viewer's fallback.
        manager.setURLModifier(() => { throw new Error('MaterialX requested an image outside its verified package.'); });
        manager.addHandler(/.*/, {
            load(path, onLoad) {
                const bitmap = bitmaps.get(path.replace(/^\.\//, ''));
                if (!bitmap) throw new Error('MaterialX requested an image outside its verified package.');
                onLoad(bitmap);
            },
        });
        let material;
        try {
            let pixels = 0;
            for (const [path, asset] of Object.entries(bundle.assets)) {
                if (!['image/png', 'image/jpeg'].includes(asset.media_type)) throw new Error('This graph uses an image format the browser cannot decode.');
                const bytes = Uint8Array.from(atob(asset.base64), (char) => char.charCodeAt(0));
                const bitmap = await createImageBitmap(new Blob([bytes], { type: asset.media_type }), { imageOrientation: 'none' });
                bitmaps.set(path, bitmap);
                pixels += bitmap.width * bitmap.height;
                if (pixels > 32 * 1024 * 1024) throw new Error('This graph exceeds the browser texture memory limit.');
            }
            // Three renders the surface graph but has no MaterialX geometry
            // displacement/volume implementation. Omit only those bindings from
            // this preview copy and disclose the difference; package stays intact.
            const document = new DOMParser().parseFromString(bundle.document, 'application/xml');
            if (document.querySelector('parsererror')) throw new Error('Invalid MaterialX XML.');
            const limitations = [];
            for (const port of document.querySelectorAll('surfacematerial > input')) {
                const selected = port.parentElement.getAttribute('name') === bundle.material_names[0];
                if (port.getAttribute('name') === 'displacementshader') {
                    port.remove(); if (selected) limitations.push('Surface shading is shown; geometric displacement is not supported in this preview.');
                } else if (port.getAttribute('name') === 'volumeshader') {
                    port.remove(); if (selected) limitations.push('Volume shading is not supported in this preview.');
                }
            }
            const result = new MaterialXLoader(manager).parse(new XMLSerializer().serializeToString(document), {
                materialName: bundle.material_names[0],
                interfaceValidator: createStrictInterfaceValidator(),
                throwOnErrors: true,
            });
            material = result.materials[bundle.material_names[0]];
            if (!material) throw new Error('MaterialX did not produce a surface shader.');
            // Nodes own the textures, unlike conventional material.map slots.
            for (const value of Object.values(material)) {
                if (value?.isNode) value.traverse((node) => { if (node.value?.isTexture) textures.add(node.value); });
            }
            if (request !== this.request || !this.host) { material.dispose(); textures.forEach((t) => t.dispose()); bitmaps.forEach((b) => b.close()); return; }
            await this.gl.renderer.compileAsync(new THREE.Mesh(this.gl.shown.geometry, material), this.gl.camera, this.gl.scene);
            if (request !== this.request || !this.host) { material.dispose(); textures.forEach((t) => t.dispose()); bitmaps.forEach((b) => b.close()); return; }
            const previous = this.gl.material;
            for (const shape of Object.values(this.gl.shapes)) shape.material = material;
            this.gl.material = material;
            previous.dispose(); this.textures?.forEach((texture) => texture.dispose()); this.textures = textures;
            this.bitmaps?.forEach((bitmap) => bitmap.close()); this.bitmaps = bitmaps;
            return { mode: 'MaterialX', warnings: [...limitations, ...result.warnings.map((entry) => entry.message), ...(bundle.losses ?? []).filter((loss) => !['provenance', 'auxiliary', 'texture_tiers', 'variants', 'tiling'].includes(loss.parameter) && !loss.detail.startsWith('MaterialX selects one of ')).map((loss) => loss.detail)] };
        } catch (error) {
            material?.dispose(); textures.forEach((texture) => texture.dispose()); bitmaps.forEach((bitmap) => bitmap.close()); throw error;
        }
    },
};
