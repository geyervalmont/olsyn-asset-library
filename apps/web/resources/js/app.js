import * as THREE from 'three';
import { HDRLoader } from 'three/addons/loaders/HDRLoader.js';
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
 * A single canvas, sculpted sample and material move to whichever host is on
 * screen, so a library of thirty quick views costs one renderer, not thirty.
 * Pages swap texture maps; nothing else is rebuilt.
 */
// studio_small_09 from Poly Haven, CC0.
const HDRI_URL = '/hdri/studio.hdr';
const CACHE_LIMIT = 16;
const preferredShapes = new Map();

/**
 * How a finish behaves beyond its maps. Categories carry this: a carpet needs
 * sheen to read as fibre, marble and solid surface need a little light through
 * them, anodised aluminium is metal. Values stay conservative: this is a
 * material library, so the sample should look like the sample.
 */
const FINISHES = {
    textile: { sheen: 0.55, sheenRoughness: 0.9, roughness: 0.95, normalScale: 1.1 },
    leather: { sheen: 0.25, sheenRoughness: 0.6, clearcoat: 0.12, clearcoatRoughness: 0.6, normalScale: 1 },
    polished: { clearcoat: 0.35, clearcoatRoughness: 0.15, roughness: 0.4 },
    // Honed, not polished: most of the library's stone is a matte finish, and
    // a roughness map raises the gloss where the sample really is glossy.
    stone: { clearcoat: 0.14, clearcoatRoughness: 0.5, transmission: 0.06, thickness: 0.5, roughness: 0.62 },
    wood: { clearcoat: 0.16, clearcoatRoughness: 0.42, roughness: 0.6 },
    metal: { metalness: 1, roughness: 0.35, anisotropy: 0.4 },
    matte: { roughness: 0.92 },
    default: { roughness: 0.7 },
};

/**
 * Project planar material maps from three axes and blend them by the surface
 * normal. Curved samples no longer funnel every texel into a sphere pole, and
 * the shader ball no longer exposes the UV islands in its source mesh.
 */
function enableSeamlessProjection(material) {
    material.userData.opalProjection = { enabled: 1, scale: 1 };
    material.customProgramCacheKey = () => 'opal-seamless-projection-v1';
    material.onBeforeCompile = (shader) => {
        const projection = material.userData.opalProjection;
        shader.uniforms.opalProjectionEnabled = { value: projection.enabled };
        shader.uniforms.opalProjectionScale = { value: projection.scale };
        material.userData.opalShader = shader;

        shader.vertexShader = shader.vertexShader
            .replace('#include <common>', `#include <common>
                varying vec3 vOpalWorldPosition;
                varying vec3 vOpalWorldNormal;`)
            .replace('#include <begin_vertex>', `#include <begin_vertex>
                vOpalWorldPosition = (modelMatrix * vec4(transformed, 1.0)).xyz;
                vOpalWorldNormal = normalize(mat3(modelMatrix) * objectNormal);`);

        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', `#include <common>
                varying vec3 vOpalWorldPosition;
                varying vec3 vOpalWorldNormal;
                uniform float opalProjectionEnabled;
                uniform float opalProjectionScale;

                vec3 opalBlendWeights(vec3 surfaceNormal) {
                    vec3 weight = pow(abs(normalize(surfaceNormal)), vec3(8.0));
                    return weight / max(weight.x + weight.y + weight.z, 0.0001);
                }

                vec4 opalSample(sampler2D textureSampler, vec3 position, vec3 surfaceNormal) {
                    vec3 weight = opalBlendWeights(surfaceNormal);
                    vec3 point = position * opalProjectionScale;
                    vec2 uvX = point.zy * vec2(surfaceNormal.x < 0.0 ? -1.0 : 1.0, 1.0);
                    vec2 uvY = point.xz * vec2(surfaceNormal.y < 0.0 ? -1.0 : 1.0, 1.0);
                    vec2 uvZ = point.xy * vec2(surfaceNormal.z < 0.0 ? -1.0 : 1.0, 1.0);
                    return texture2D(textureSampler, uvX) * weight.x
                        + texture2D(textureSampler, uvY) * weight.y
                        + texture2D(textureSampler, uvZ) * weight.z;
                }

                vec3 opalNormalSample(sampler2D textureSampler, vec3 position, vec3 surfaceNormal, vec2 strength) {
                    vec3 n = normalize(surfaceNormal);
                    vec3 weight = opalBlendWeights(n);
                    vec3 point = position * opalProjectionScale;
                    float signX = n.x < 0.0 ? -1.0 : 1.0;
                    float signY = n.y < 0.0 ? -1.0 : 1.0;
                    float signZ = n.z < 0.0 ? -1.0 : 1.0;
                    vec3 normalX = texture2D(textureSampler, point.zy * vec2(signX, 1.0)).xyz * 2.0 - 1.0;
                    vec3 normalY = texture2D(textureSampler, point.xz * vec2(signY, 1.0)).xyz * 2.0 - 1.0;
                    vec3 normalZ = texture2D(textureSampler, point.xy * vec2(-signZ, 1.0)).xyz * 2.0 - 1.0;
                    normalX.xy *= strength;
                    normalY.xy *= strength;
                    normalZ.xy *= strength;
                    normalX = vec3(normalX.xy + n.zy, abs(normalX.z) * n.x).zyx;
                    normalY = vec3(normalY.xy + n.xz, abs(normalY.z) * n.y).xzy;
                    normalZ = vec3(normalZ.xy + n.xy, abs(normalZ.z) * n.z);
                    return normalize(normalX * weight.x + normalY * weight.y + normalZ * weight.z);
                }`)
            .replace('#include <map_fragment>', `
                #ifdef USE_MAP
                    vec4 sampledDiffuseColor = opalProjectionEnabled > 0.5
                        ? opalSample(map, vOpalWorldPosition, vOpalWorldNormal)
                        : texture2D(map, vMapUv);
                    diffuseColor *= sampledDiffuseColor;
                #endif`)
            .replace('#include <roughnessmap_fragment>', `
                float roughnessFactor = roughness;
                #ifdef USE_ROUGHNESSMAP
                    vec4 texelRoughness = opalProjectionEnabled > 0.5
                        ? opalSample(roughnessMap, vOpalWorldPosition, vOpalWorldNormal)
                        : texture2D(roughnessMap, vRoughnessMapUv);
                    roughnessFactor *= texelRoughness.g;
                #endif`)
            .replace('#include <metalnessmap_fragment>', `
                float metalnessFactor = metalness;
                #ifdef USE_METALNESSMAP
                    vec4 texelMetalness = opalProjectionEnabled > 0.5
                        ? opalSample(metalnessMap, vOpalWorldPosition, vOpalWorldNormal)
                        : texture2D(metalnessMap, vMetalnessMapUv);
                    metalnessFactor *= texelMetalness.b;
                #endif`)
            .replace('#include <bumpmap_pars_fragment>', `
                #ifdef USE_BUMPMAP
                    uniform sampler2D bumpMap;
                    uniform float bumpScale;

                    float opalHeight(vec3 position) {
                        return opalSample(bumpMap, position, vOpalWorldNormal).r;
                    }

                    vec2 dHdxy_fwd() {
                        if (opalProjectionEnabled > 0.5) {
                            float centre = bumpScale * opalHeight(vOpalWorldPosition);
                            return vec2(
                                bumpScale * opalHeight(vOpalWorldPosition + dFdx(vOpalWorldPosition)) - centre,
                                bumpScale * opalHeight(vOpalWorldPosition + dFdy(vOpalWorldPosition)) - centre
                            );
                        }
                        vec2 dSTdx = dFdx(vBumpMapUv);
                        vec2 dSTdy = dFdy(vBumpMapUv);
                        float centre = bumpScale * texture2D(bumpMap, vBumpMapUv).x;
                        return vec2(
                            bumpScale * texture2D(bumpMap, vBumpMapUv + dSTdx).x - centre,
                            bumpScale * texture2D(bumpMap, vBumpMapUv + dSTdy).x - centre
                        );
                    }

                    vec3 perturbNormalArb(vec3 surf_pos, vec3 surf_norm, vec2 dHdxy, float faceDirection) {
                        vec3 vSigmaX = normalize(dFdx(surf_pos.xyz));
                        vec3 vSigmaY = normalize(dFdy(surf_pos.xyz));
                        vec3 R1 = cross(vSigmaY, surf_norm);
                        vec3 R2 = cross(surf_norm, vSigmaX);
                        float fDet = dot(vSigmaX, R1) * faceDirection;
                        vec3 vGrad = sign(fDet) * (dHdxy.x * R1 + dHdxy.y * R2);
                        return normalize(abs(fDet) * surf_norm - vGrad);
                    }
                #endif`)
            .replace('#include <normal_fragment_maps>', `
                #ifdef USE_NORMALMAP_OBJECTSPACE
                    normal = texture2D(normalMap, vNormalMapUv).xyz * 2.0 - 1.0;
                    #ifdef FLIP_SIDED
                        normal = -normal;
                    #endif
                    #ifdef DOUBLE_SIDED
                        normal = normal * faceDirection;
                    #endif
                    normal = normalize(normalMatrix * normal);
                #elif defined(USE_NORMALMAP_TANGENTSPACE)
                    if (opalProjectionEnabled > 0.5) {
                        normal = normalize(mat3(viewMatrix) * opalNormalSample(
                            normalMap,
                            vOpalWorldPosition,
                            vOpalWorldNormal,
                            normalScale
                        ));
                    } else {
                        vec3 mapN = texture2D(normalMap, vNormalMapUv).xyz * 2.0 - 1.0;
                        mapN.xy *= normalScale;
                        normal = normalize(tbn * mapN);
                    }
                #elif defined(USE_BUMPMAP)
                    normal = perturbNormalArb(-vViewPosition, normal, dHdxy_fwd(), faceDirection);
                #endif`);
    };
}

function setSeamlessProjection(material, enabled, scale) {
    material.userData.opalProjection = { enabled: enabled ? 1 : 0, scale };
    const shader = material.userData.opalShader;

    if (shader) {
        shader.uniforms.opalProjectionEnabled.value = enabled ? 1 : 0;
        shader.uniforms.opalProjectionScale.value = scale;
    }
}

const stage = {
    gl: null,
    booting: null,
    host: null,
    sizeObserver: null,
    frame: null,
    visible: true,
    pending: null,
    shownKey: null,
    framing: 1.08,
    verticalBias: 0,
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
            // Khronos PBR Neutral: made for showing products, so a colour on
            // screen is the colour of the sample rather than a filmic grade.
            renderer.toneMapping = THREE.NeutralToneMapping;
            renderer.toneMappingExposure = 0.95;
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.shadowMap.enabled = true;
            renderer.shadowMap.type = THREE.PCFSoftShadowMap;

            const scene = new THREE.Scene();
            const environment = await loadEnvironment(renderer);
            scene.environment = environment;
            scene.environmentIntensity = 0.9;
            // The studio sits behind the sample as a soft gradient: enough to
            // ground it, never enough to compete with the material.
            scene.background = environment;
            scene.backgroundBlurriness = 0.85;
            scene.backgroundIntensity = 0.5;

            const camera = new THREE.PerspectiveCamera(32, 1, 0.01, 100);
            camera.position.set(0, 0.6, 3.4);

            const controls = new OrbitControls(camera, canvas);
            controls.enableDamping = true;
            controls.enablePan = false;
            // Zooming follows the pointer, and close enough to read a weave.
            controls.zoomToCursor = true;
            controls.autoRotate = ! window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            controls.autoRotateSpeed = 1.4;
            // Turning by hand pauses the drift; it resumes when let go.
            controls.addEventListener('start', () => { controls.autoRotate = false; });
            controls.addEventListener('end', () => {
                controls.autoRotate = ! window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            });
            canvas.addEventListener('dblclick', () => this.frame_());

            // The environment does the lighting; this one light is here to
            // drop a soft shadow, which is what seats the sample on a surface.
            const sun = new THREE.DirectionalLight(0xffffff, 1);
            sun.position.set(2.6, 4.2, 2.2);
            sun.castShadow = true;
            sun.shadow.mapSize.set(1024, 1024);
            sun.shadow.camera.near = 0.5;
            sun.shadow.camera.far = 14;
            sun.shadow.camera.left = -3;
            sun.shadow.camera.right = 3;
            sun.shadow.camera.top = 3;
            sun.shadow.camera.bottom = -3;
            sun.shadow.bias = -0.0006;
            sun.shadow.normalBias = 0.02;
            sun.shadow.radius = 5;
            scene.add(sun);

            const ground = new THREE.Mesh(new THREE.PlaneGeometry(40, 40), new THREE.ShadowMaterial({ opacity: 0.22 }));
            ground.rotation.x = -Math.PI / 2;
            ground.receiveShadow = true;
            scene.add(ground);

            const material = new THREE.MeshPhysicalMaterial({ color: 0xcfcbc1, roughness: 0.8, metalness: 0 });
            enableSeamlessProjection(material);
            const shapes = {
                ball: createSculptedBall(material),
                shader: createShaderBall(material),
                sphere: sitOnGround(new THREE.Mesh(new THREE.SphereGeometry(1.1, 128, 64), material)),
                // A slab rather than a plane: the sample keeps a face while
                // the view turns, which a single-sided plane does not.
                panel: sitOnGround(new THREE.Mesh(new THREE.BoxGeometry(2.4, 2.4, 0.07, 128, 128, 2), material)),
                cube: sitOnGround(new THREE.Mesh(new THREE.BoxGeometry(1.6, 1.6, 1.6, 96, 96, 96), material)),
            };

            Object.values(shapes).forEach((shape) => shape.traverse((object) => {
                if (object.isMesh) {
                    object.castShadow = true;
                    object.receiveShadow = true;
                }
            }));

            scene.add(shapes.ball);

            this.gl = {
                canvas, renderer, scene, camera, controls, material, shapes, ground, sun,
                shown: shapes.ball,
                shapeName: 'ball',
                surface: null,
                loader: new THREE.TextureLoader(),
                anisotropy: renderer.capabilities.getMaxAnisotropy(),
            };

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
        this.framing = options.framing ?? 1.08;
        this.verticalBias = options.verticalBias ?? 0;
        host.appendChild(gl.canvas);
        gl.controls.enableZoom = options.zoom !== false;
        gl.controls.enableRotate = options.rotate !== false;
        gl.canvas.title = options.zoom === false ? '' : 'Drag to turn · scroll or pinch to zoom · double-click to reset';

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
        const distance = this.framing * Math.max(
            (size.y / 2) / Math.tan(vertical / 2),
            (Math.max(size.x, size.z) / 2) / Math.tan(horizontal / 2),
        );

        const target = centre.clone();
        target.y -= size.y * this.verticalBias;
        gl.controls.target.copy(target);
        gl.camera.position.set(centre.x, centre.y + size.y * 0.12, centre.z + distance);
        gl.controls.minDistance = distance * 0.12;
        gl.controls.maxDistance = distance * 3;
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
        gl.shapeName = name;
        this.applyProjection();
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
                const finish = FINISHES[set.finish] ?? FINISHES.default;
                const material = gl.material;

                const reliefMap = maps.height ?? maps.bump ?? null;
                material.map = maps.base_color ?? null;
                // Generated sets carry a physically scaled height channel. It
                // gives continuous relief under triplanar projection; a normal
                // map remains the fallback for imported map-only materials.
                material.normalMap = reliefMap ? null : (maps.normal ?? null);
                material.bumpMap = reliefMap;
                // Geometry displacement is expressed in object units, while
                // bumpScale controls the slope reconstructed from a normalised
                // height map. Reusing the tiny displacement number made deep
                // masonry joints look painted on. Convert relative relief to a
                // useful slope, with conservative limits for noisy scans.
                material.bumpScale = reliefMap
                    ? Math.max(0.06, Math.min(2, (set.displacement_scale ?? 0) * 100))
                    : 0;
                material.roughnessMap = maps.roughness ?? null;
                material.metalnessMap = maps.metallic ?? null;
                material.aoMap = maps.ao ?? null;
                material.aoMapIntensity = maps.ao ? 1 : 0;
                material.emissiveMap = maps.emissive ?? null;
                material.emissive.set(maps.emissive ? 0xffffff : 0x000000);
                material.alphaMap = maps.opacity ?? null;
                material.transparent = Boolean(maps.opacity);
                material.color.set(maps.base_color ? 0xffffff : (set.hex || '#cfcbc1'));

                // A map drives the channel; the finish sets what a map cannot.
                material.roughness = maps.roughness ? 1 : (finish.roughness ?? 0.7);
                material.metalness = maps.metallic ? 1 : (finish.metalness ?? 0);
                material.normalScale.setScalar(finish.normalScale ?? 1);
                material.sheen = finish.sheen ?? 0;
                material.sheenRoughness = finish.sheenRoughness ?? 1;
                material.sheenColor.set(0xffffff);
                material.clearcoat = finish.clearcoat ?? 0;
                material.clearcoatRoughness = finish.clearcoatRoughness ?? 0.3;
                material.anisotropy = finish.anisotropy ?? 0;
                // Light through the sample: marble and solid surface only, and
                // never far enough to see the other side.
                material.transmission = finish.transmission ?? 0;
                material.thickness = finish.thickness ?? 0;
                material.attenuationColor.set(0xffffff);
                material.attenuationDistance = finish.transmission ? 1.4 : Infinity;
                material.envMapIntensity = 1;
                gl.surface = { set, maps, repeat: Math.max(1, objectSizeMm / Math.max(set.tile_mm || 1000, 1)) };
                this.applyProjection();
                material.needsUpdate = true;

                this.shownKey = set.key;
                resolve();
            }, 90);
        });
    },

    /** Curved samples use triplanar relief; only the flat sample displaces vertices. */
    applyProjection() {
        const gl = this.gl;

        if (! gl || ! gl.surface) {
            return;
        }

        const { set, maps, repeat } = gl.surface;
        const planar = gl.shapeName === 'panel';
        const bounds = new THREE.Box3().setFromObject(gl.shown).getSize(new THREE.Vector3());
        const span = Math.max(bounds.x, bounds.y, bounds.z, 0.001);
        setSeamlessProjection(gl.material, ! planar, repeat / span);

        // UV displacement tears duplicated vertices apart at model seams and
        // collapses into a singularity at sphere poles. A subdivided flat
        // sample is the only geometry on which literal displacement is useful.
        gl.material.displacementMap = planar && set.displacement_scale > 0 ? (maps.height ?? null) : null;
        gl.material.displacementScale = gl.material.displacementMap ? set.displacement_scale : 0;
        gl.material.displacementBias = gl.material.displacementMap ? -set.displacement_scale * 0.08 : 0;
        gl.material.needsUpdate = true;
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
                texture.anisotropy = gl.anisotropy;
                // The model carries one UV set; ambient occlusion reads it too.
                texture.channel = 0;
                resolve(texture);
            }, undefined, () => resolve(null));
        });

        const roles = [
            ['base_color', THREE.SRGBColorSpace],
            ['normal', THREE.NoColorSpace],
            ['roughness', THREE.NoColorSpace],
            ['metallic', THREE.NoColorSpace],
            ['ao', THREE.NoColorSpace],
            ['bump', THREE.NoColorSpace],
            ['height', THREE.NoColorSpace],
            ['emissive', THREE.SRGBColorSpace],
            ['opacity', THREE.NoColorSpace],
        ];

        const loading = Promise.all(roles.map(([role, colorSpace]) => load(set[role], colorSpace)))
            .then((textures) => Object.fromEntries(roles.map(([role], index) => [role, textures[index]])));

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
 * One continuous sculpted surface for reading grazing light and relief. The
 * previous imported shader ball was assembled from overlapping open shells;
 * those gaps looked like broken texture seams on brick and board patterns.
 */
function createSculptedBall(material) {
    const geometry = new THREE.SphereGeometry(1.08, 160, 96);
    const positions = geometry.attributes.position;
    const normal = new THREE.Vector3();

    for (let index = 0; index < positions.count; index++) {
        normal.fromBufferAttribute(positions, index).normalize();
        const latitude = Math.asin(THREE.MathUtils.clamp(normal.y, -1, 1));
        const longitude = Math.atan2(normal.z, normal.x);
        const equator = Math.cos(latitude) ** 2;
        const radius = 1.04
            + Math.sin(longitude * 5 + Math.sin(latitude * 2) * 1.4) * equator * 0.065
            + Math.sin(latitude * 6) * 0.035;
        positions.setXYZ(index, normal.x * radius, normal.y * radius, normal.z * radius);
    }

    positions.needsUpdate = true;
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return sitOnGround(new THREE.Mesh(geometry, material));
}

/**
 * A closed technical shader ball with convex lobes, recessed dimples and
 * crisp reference grooves. It exercises grazing highlights and relief without
 * bringing back the overlapping, open shells in the old imported model.
 */
function createShaderBall(material) {
    const geometry = new THREE.SphereGeometry(1.08, 192, 128);
    const positions = geometry.attributes.position;
    const normal = new THREE.Vector3();
    const features = [
        { direction: new THREE.Vector3(0.58, 0.44, 0.68).normalize(), spread: 0.22, depth: 0.115 },
        { direction: new THREE.Vector3(-0.62, 0.32, 0.72).normalize(), spread: 0.18, depth: -0.14 },
        { direction: new THREE.Vector3(0.48, -0.42, 0.77).normalize(), spread: 0.15, depth: -0.105 },
        { direction: new THREE.Vector3(-0.5, -0.5, 0.7).normalize(), spread: 0.2, depth: 0.085 },
    ];

    for (let index = 0; index < positions.count; index++) {
        normal.fromBufferAttribute(positions, index).normalize();
        const longitude = Math.atan2(normal.z, normal.x);
        const latitude = Math.asin(THREE.MathUtils.clamp(normal.y, -1, 1));
        const body = Math.cos(latitude) ** 2;
        let radius = 1.035 + Math.sin(longitude * 3 - 0.45) * body * 0.028;

        // A narrow equatorial and meridian datum reads immediately under a
        // patterned material, while the smooth falloff keeps the mesh closed.
        radius -= Math.exp(-((normal.y / 0.052) ** 2)) * 0.045;
        radius -= Math.exp(-((normal.x / 0.058) ** 2)) * body * 0.032;

        for (const feature of features) {
            const angularDistance = 1 - THREE.MathUtils.clamp(normal.dot(feature.direction), -1, 1);
            radius += feature.depth * Math.exp(-angularDistance / (feature.spread ** 2));
        }

        positions.setXYZ(index, normal.x * radius, normal.y * radius, normal.z * radius);
    }

    positions.needsUpdate = true;
    geometry.computeVertexNormals();
    geometry.computeBoundingSphere();

    return sitOnGround(new THREE.Mesh(geometry, material));
}

/** Drop an object so its lowest point rests on y = 0. */
function sitOnGround(object) {
    const box = new THREE.Box3().setFromObject(object);
    object.position.y -= box.min.y;

    return object;
}

/**
 * The studio environment. A real HDRI lights the sample and, blurred, sits
 * behind it; a procedural room stands in if the file cannot be fetched.
 */
async function loadEnvironment(renderer) {
    const pmrem = new THREE.PMREMGenerator(renderer);
    pmrem.compileEquirectangularShader();

    try {
        const hdr = await new HDRLoader().loadAsync(HDRI_URL);
        const environment = pmrem.fromEquirectangular(hdr).texture;
        hdr.dispose();

        return environment;
    } catch (error) {
        return pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    }
}

document.addEventListener('alpine:init', () => {
    /**
     * Binds a host element to the shared stage and follows a colourway.
     */
    window.Alpine.data('materialViewer', (config) => ({
        sets: config.sets ?? {},
        objectSizeMm: config.objectSizeMm ?? 1000,
        shapeKey: config.shapeKey ?? null,
        shape: (config.shapeKey ? preferredShapes.get(config.shapeKey) : null) ?? config.shape ?? 'ball',
        framing: config.framing ?? 1.08,
        verticalBias: config.verticalBias ?? 0,
        zoom: config.zoom !== false,
        status: 'idle',
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
            if (! this.available) {
                return;
            }

            this.status = 'loading';
            this.host = this.$refs.stage ?? this.$el;
            stage.attach(this.host, { zoom: this.zoom, framing: this.framing, verticalBias: this.verticalBias }).then(() => {
                stage.setShape(this.shape);
                this.status = 'ready';
            });

            this.$watch('shape', (value) => {
                stage.setShape(value);

                if (this.shapeKey) {
                    preferredShapes.set(this.shapeKey, value);
                }
            });
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

            this.variantId = variantId;

            if ((this.view === 'map' || this.view === 'tile') && ! this.activeMap) {
                this.inspectSurface();
            }

            this.status = 'loading';
            stage.show(set, this.objectSizeMm).then(() => {
                this.status = 'ready';
            });
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
