<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Materials\CreateMaterial;
use App\Jobs\BakeProceduralMaterial;
use App\Library\Procedural\ProceduralBaker;
use App\Library\Procedural\ProceduralRecipes;
use App\Models\Category;
use App\Models\Definition;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Variant;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Material Studio')] class extends Component
{
    #[Url]
    public string $mode = 'new';

    #[Url(as: 'material')]
    public string $materialCode = '';

    #[Url(as: 'variant')]
    public string $variantCode = '';

    public string $name = '';

    public string $category_id = '';

    public string $supplier_id = '';

    public string $new_supplier = '';

    public string $collection = '';

    public string $supplier_product_code = '';

    public string $colourway = '';

    public string $colourway_code = '';

    public string $generator = 'paint';

    /** @var array<string, mixed> */
    public array $recipe = [];

    public int $resolution = 1024;

    public float $width_mm = 1000;

    public float $height_mm = 1000;

    public int $seed = 42;

    /** @var array<string, string> */
    public array $previewMaps = [];

    public string $previewError = '';

    public string $previewStatus = 'idle';

    public int $previewRevision = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        if ($this->materialCode !== '') {
            $this->mode = 'existing';
        }

        $this->mode = in_array($this->mode, ['new', 'existing'], true) ? $this->mode : 'new';
        $this->recipe = ProceduralRecipes::defaults($this->generator);
        $this->loadExistingDefinition();
        $this->refreshPreview(app(ProceduralBaker::class));
    }

    /** @return array<string, string> */
    public function generators(): array
    {
        return ProceduralRecipes::generators();
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get();
    }

    #[Computed]
    public function materials(): Collection
    {
        return Material::query()->visibleTo(auth()->user())->orderBy('name')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function selectedMaterial(): ?Material
    {
        $material = $this->materialCode === '' ? null : Material::resolveCode($this->materialCode);

        return $material !== null && $material->isVisibleTo(auth()->user()) ? $material : null;
    }

    #[Computed]
    public function variants(): Collection
    {
        return $this->selectedMaterial?->variants()->with('definition')->orderBy('position')->get() ?? collect();
    }

    public function updatedMode(): void
    {
        $this->mode = in_array($this->mode, ['new', 'existing'], true) ? $this->mode : 'new';
        $this->resetValidation();
    }

    public function updatedMaterialCode(): void
    {
        $this->variantCode = '';
        unset($this->selectedMaterial, $this->variants);
        $this->clearPreview();
    }

    public function updatedVariantCode(): void
    {
        $this->loadExistingDefinition();
        $this->clearPreview();
        $this->refreshPreview(app(ProceduralBaker::class));
    }

    public function updated(string $property): void
    {
        if (preg_match('/^(recipe\.|width_mm$|height_mm$|seed$)/', $property) === 1) {
            $this->refreshPreview(app(ProceduralBaker::class));
        }
    }

    public function chooseGenerator(string $generator): void
    {
        abort_unless(array_key_exists($generator, ProceduralRecipes::generators()), 404);
        $this->generator = $generator;
        $this->recipe = ProceduralRecipes::defaults($generator);
        [$this->width_mm, $this->height_mm] = match ($generator) {
            'masonry' => [480.0, 172.0],
            'timber' => [1203.0, 286.0],
            default => [1000.0, 1000.0],
        };
        $this->clearPreview();
        $this->resetValidation();
        $this->refreshPreview(app(ProceduralBaker::class));
    }

    public function addPaletteColour(string $key): void
    {
        if (! in_array($key, ['unit_colours', 'colours', 'chip_colours'], true)) {
            return;
        }

        $values = is_array($this->recipe[$key] ?? null) ? $this->recipe[$key] : [];

        if (count($values) < 8) {
            $values[] = end($values) ?: '#808080';
            $this->recipe[$key] = array_values($values);
            $this->refreshPreview(app(ProceduralBaker::class));
        }
    }

    public function removePaletteColour(string $key, int $index): void
    {
        $values = is_array($this->recipe[$key] ?? null) ? $this->recipe[$key] : [];

        if (count($values) > 1 && array_key_exists($index, $values)) {
            unset($values[$index]);
            $this->recipe[$key] = array_values($values);
            $this->refreshPreview(app(ProceduralBaker::class));
        }
    }

    public function randomizeSeed(): void
    {
        $this->seed = random_int(0, 2147483647);
        $this->refreshPreview(app(ProceduralBaker::class));
    }

    public function resetRecipe(): void
    {
        $this->recipe = ProceduralRecipes::defaults($this->generator);
        $this->resetValidation();
        $this->refreshPreview(app(ProceduralBaker::class));
    }

    public function preview(ProceduralBaker $baker): void
    {
        $this->validate($this->recipeRules());

        if (! $this->validateRepeat()) {
            return;
        }

        $this->refreshPreview($baker);
    }

    private function refreshPreview(ProceduralBaker $baker): void
    {
        $validator = Validator::make([
            'generator' => $this->generator,
            'resolution' => $this->resolution,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'seed' => $this->seed,
            'recipe' => $this->recipe,
        ], $this->recipeRules());

        if ($validator->fails() || ! $this->validateRepeat(false)) {
            $this->previewStatus = 'waiting';

            return;
        }

        $this->previewError = '';

        if (! $baker->available()) {
            $this->previewError = __('The USD toolbox is not installed on this server.');
            $this->previewStatus = 'unavailable';

            return;
        }

        try {
            $bake = $baker->bake($this->definitionPayload(320));
            $this->previewMaps = [];

            foreach ($bake->assets as $asset) {
                if (str_starts_with($asset->mimeType, 'image/')) {
                    $this->previewMaps[$asset->role] = 'data:'.$asset->mimeType.';base64,'.base64_encode($asset->contents);
                }
            }
            $this->previewStatus = 'ready';
            $this->previewRevision++;
        } catch (Throwable $exception) {
            report($exception);
            $this->previewError = $exception->getMessage();
            $this->previewStatus = 'error';
        }
    }

    public function save(CreateMaterial $createMaterial, AddVariant $addVariant): void
    {
        $validated = $this->validate([
            ...$this->recipeRules(),
            'mode' => ['required', 'in:new,existing'],
            'materialCode' => [$this->mode === 'existing' ? 'required' : 'nullable', 'string'],
            'variantCode' => [$this->mode === 'existing' ? 'required' : 'nullable', 'string'],
            'name' => [$this->mode === 'new' ? 'required' : 'nullable', 'string', 'max:120'],
            'category_id' => [$this->mode === 'new' ? 'required' : 'nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'new_supplier' => ['nullable', 'string', 'max:120'],
            'collection' => ['nullable', 'string', 'max:120'],
            'supplier_product_code' => ['nullable', 'string', 'max:120'],
            'colourway' => [$this->mode === 'existing' && $this->variantCode === '__new' ? 'required' : 'nullable', 'string', 'max:120'],
            'colourway_code' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $this->validateRepeat()) {
            return;
        }

        $user = auth()->user();

        if ($this->mode === 'new') {
            $supplierId = $validated['supplier_id'] !== '' ? (int) $validated['supplier_id'] : null;

            if ($supplierId === null && trim($validated['new_supplier'] ?? '') !== '') {
                $supplierId = (int) Supplier::query()->firstOrCreate(['name' => trim($validated['new_supplier'])])->getKey();
            }

            $material = $createMaterial->handle([
                'name' => $validated['name'],
                'category_id' => (int) $validated['category_id'],
                'supplier_id' => $supplierId,
                'collection' => $validated['collection'] ?: null,
                'supplier_product_code' => $validated['supplier_product_code'] ?: null,
                'tile_width_mm' => $this->width_mm,
                'tile_height_mm' => $this->height_mm,
                'contributed_by_tenant_id' => Tenant::current()?->getKey(),
                'contributed_by_user_id' => $user?->getKey(),
            ], [['attributes' => array_filter([
                'colourway' => $validated['colourway'] !== '' ? ['value' => $validated['colourway'], 'supplier_code' => $validated['colourway_code'] ?: null] : null,
            ])]]);
            $variant = $material->variants->firstOrFail();
        } else {
            $material = $this->selectedMaterial;

            if ($material === null) {
                $this->addError('materialCode', __('Choose a material you can access.'));

                return;
            }

            $variant = $this->variantCode === '__new'
                ? $addVariant->handle($material, ['colourway' => ['value' => $validated['colourway'], 'supplier_code' => $validated['colourway_code'] ?: null]])
                : $material->variants()->where('code', $this->variantCode)->first();

            if ($variant === null) {
                $this->addError('variantCode', __('Choose an existing variant or create a new one.'));

                return;
            }
        }

        $definition = $variant->definition()->firstOrNew();
        $definition->fill([
            'generator' => $this->generator,
            'generator_version' => null,
            'parameters' => [
                'seed' => $this->seed,
                'output' => ['width_px' => $this->resolution, 'height_px' => $this->resolution, 'width_mm' => $this->width_mm, 'height_mm' => $this->height_mm],
                'recipe' => $this->recipePayload(),
            ],
        ])->save();

        $run = BakeProceduralMaterial::forDefinition($definition, $user);
        Flux::toast(variant: 'success', text: __('Saved the recipe and queued a candidate bake (run :run).', ['run' => substr($run->uuid, 0, 8)]));
        $this->redirect(route('materials.show', $material), navigate: true);
    }

    /** @return array<string, list<string>> */
    private function recipeRules(): array
    {
        return [
            'generator' => ['required', 'in:'.implode(',', array_keys(ProceduralRecipes::generators()))],
            'resolution' => ['required', 'integer', 'in:512,1024,2048,4096'],
            'width_mm' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'height_mm' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'seed' => ['required', 'integer', 'min:0'],
            ...ProceduralRecipes::rules($this->generator),
        ];
    }

    /** @return array<string, mixed> */
    private function definitionPayload(?int $resolution = null): array
    {
        return [
            'schema' => 1,
            'width_px' => $resolution ?? $this->resolution,
            'height_px' => $resolution ?? $this->resolution,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'seed' => $this->seed,
            'generator' => $this->generator,
            'parameters' => $this->recipePayload(),
        ];
    }

    /** @return array<string, mixed> */
    private function recipePayload(): array
    {
        $booleans = ['stagger', 'basket'];
        $numbers = ['roughness', 'variation', 'texture_depth', 'unit_width_mm', 'unit_height_mm', 'joint_mm', 'edge_depth_mm', 'tone_variation', 'board_width_mm', 'board_length_mm', 'grain_strength', 'chip_size_mm', 'density', 'chip_depth_mm', 'thread_mm', 'depth_mm'];
        $recipe = $this->recipe;

        foreach ($recipe as $key => $value) {
            if (in_array($key, $numbers, true)) {
                $recipe[$key] = (float) $value;
            } elseif (in_array($key, $booleans, true)) {
                $recipe[$key] = (bool) $value;
            }
        }

        return $recipe;
    }

    private function loadExistingDefinition(): void
    {
        if ($this->variantCode === '' || $this->variantCode === '__new') {
            return;
        }

        $variant = Variant::resolveCode($this->variantCode);
        $definition = $variant?->material_id === $this->selectedMaterial?->getKey() ? $variant->definition : null;

        if (! $definition instanceof Definition) {
            return;
        }

        $this->generator = $definition->generator;
        $this->recipe = is_array($definition->parameters['recipe'] ?? null) ? $definition->parameters['recipe'] : ProceduralRecipes::defaults($this->generator);
        $output = is_array($definition->parameters['output'] ?? null) ? $definition->parameters['output'] : [];
        $this->resolution = (int) ($output['width_px'] ?? 1024);
        $this->width_mm = (float) ($output['width_mm'] ?? $variant->effectiveTileWidthMm() ?? 1000);
        $this->height_mm = (float) ($output['height_mm'] ?? $variant->effectiveTileHeightMm() ?? 1000);
        $this->seed = (int) ($definition->parameters['seed'] ?? 42);
    }

    private function validateRepeat(bool $showErrors = true): bool
    {
        $valid = true;

        if ($this->generator === 'masonry') {
            $unitWidth = (float) ($this->recipe['unit_width_mm'] ?? 0);
            $unitHeight = (float) ($this->recipe['unit_height_mm'] ?? 0);
            $joint = (float) ($this->recipe['joint_mm'] ?? 0);

            if ($joint >= min($unitWidth, $unitHeight)) {
                if ($showErrors) {
                    $this->addError('recipe.joint_mm', __('Joint must be smaller than both unit dimensions.'));
                }
                $valid = false;
            }

            $valid = $this->aligned('width_mm', $this->width_mm, $unitWidth + $joint, 1, $showErrors) && $valid;
            $multiple = match ($this->recipe['bond'] ?? 'stack') {
                'quarter' => 4, 'running' => 2, default => 1
            };
            $valid = $this->aligned('height_mm', $this->height_mm, $unitHeight + $joint, $multiple, $showErrors) && $valid;
        } elseif ($this->generator === 'timber') {
            $joint = (float) ($this->recipe['joint_mm'] ?? 0);
            $valid = $this->aligned('width_mm', $this->width_mm, (float) ($this->recipe['board_length_mm'] ?? 0) + $joint, 1, $showErrors) && $valid;
            $valid = $this->aligned('height_mm', $this->height_mm, (float) ($this->recipe['board_width_mm'] ?? 0) + $joint, ($this->recipe['stagger'] ?? false) ? 2 : 1, $showErrors) && $valid;
        } elseif ($this->generator === 'textile') {
            $pitch = (float) ($this->recipe['thread_mm'] ?? 0) * 2;
            $valid = $this->aligned('width_mm', $this->width_mm, $pitch, 1, $showErrors) && $valid;
            $valid = $this->aligned('height_mm', $this->height_mm, $pitch, 1, $showErrors) && $valid;
        }

        return $valid;
    }

    private function aligned(string $field, float $dimension, float $pitch, int $multiple, bool $showError): bool
    {
        if ($pitch <= 0) {
            return false;
        }

        $repeats = $dimension / $pitch;
        $count = max($multiple, (int) round($repeats / $multiple) * $multiple);

        if (abs($repeats - round($repeats)) <= 0.0001 && ((int) round($repeats)) % $multiple === 0) {
            return true;
        }

        if ($showError) {
            $this->addError($field, __('Use :size mm for a complete repeat at these settings.', ['size' => round($pitch * $count, 3)]));
        }

        return false;
    }

    private function clearPreview(): void
    {
        $this->previewMaps = [];
        $this->previewError = '';
        $this->previewStatus = 'idle';
    }
}; ?>

<section class="ui-material-studio">
    <div class="ui-material-studio__head">
        <div>
            <x-ui.eyebrow>{{ __('Material authoring') }}</x-ui.eyebrow>
            <h1>{{ __('Studio') }}</h1>
            <p>{{ __('Tune a physically scaled recipe and see the production engine respond as you work.') }}</p>
        </div>
        <div class="ui-material-studio__head-actions">
            <span class="ui-studio-engine" data-status="{{ $previewStatus }}" aria-live="polite">
                <i></i>
                <span wire:loading.remove>{{ $previewStatus === 'ready' ? __('Live preview') : ($previewStatus === 'waiting' ? __('Complete a valid repeat') : __('Preview unavailable')) }}</span>
                <span wire:loading>{{ __('Updating material…') }}</span>
            </span>
            <x-ui.button :href="route('materials.create')" variant="quiet" wire:navigate>{{ __('Import maps') }}</x-ui.button>
        </div>
    </div>

    <form wire:submit="save" class="ui-studio-workbench">
        <nav class="ui-studio-recipes" aria-label="{{ __('Material recipe') }}">
            @foreach ($this->generators() as $slug => $label)
                @php
                    [$mark, $description] = match ($slug) {
                        'paint' => ['P', __('Solid finish')],
                        'masonry' => ['M', __('Bonded units')],
                        'timber' => ['T', __('Board layout')],
                        'terrazzo' => ['Z', __('Seeded aggregate')],
                        default => ['W', __('Woven surface')],
                    };
                @endphp
                <button type="button" wire:click="chooseGenerator('{{ $slug }}')" @class(['is-active' => $generator === $slug]) data-test="generator-{{ $slug }}">
                    <span>{{ $mark }}</span>
                    <strong>{{ $label }}</strong>
                    <small>{{ $description }}</small>
                </button>
            @endforeach
        </nav>

        <div class="ui-studio-layout">
            <aside class="ui-studio-properties ui-studio-properties--recipe">
                <header class="ui-studio-section-head">
                    <div><span>01</span><h2>{{ $this->generators()[$generator] }}</h2></div>
                    <button type="button" wire:click="resetRecipe">{{ __('Reset') }}</button>
                </header>

                <div class="ui-studio-property-group">
                    @if ($generator === 'paint')
                        <x-ui.studio-colour :label="__('Paint colour')" model="recipe.colour" :value="$recipe['colour']" id="paint-colour" />
                        <x-ui.studio-slider :label="__('Roughness')" model="recipe.roughness" :value="$recipe['roughness']" />
                        <x-ui.studio-slider :label="__('Tone variation')" model="recipe.variation" :value="$recipe['variation']" />
                        <x-ui.studio-slider :label="__('Surface relief')" model="recipe.texture_depth" :value="$recipe['texture_depth']" />
                    @elseif ($generator === 'masonry')
                        <div class="ui-studio-measure-grid">
                            <x-ui.studio-measure :label="__('Unit width')" model="recipe.unit_width_mm" :value="$recipe['unit_width_mm']" min="1" />
                            <x-ui.studio-measure :label="__('Unit height')" model="recipe.unit_height_mm" :value="$recipe['unit_height_mm']" min="1" />
                            <x-ui.studio-measure :label="__('Joint')" model="recipe.joint_mm" :value="$recipe['joint_mm']" />
                            <x-ui.studio-measure :label="__('Edge depth')" model="recipe.edge_depth_mm" :value="$recipe['edge_depth_mm']" />
                        </div>
                        <label class="ui-studio-select"><span>{{ __('Bond') }}</span><select wire:model.live="recipe.bond"><option value="stack">{{ __('Stack') }}</option><option value="running">{{ __('Running') }}</option><option value="quarter">{{ __('Quarter') }}</option></select></label>
                        <x-ui.studio-colour :label="__('Joint colour')" model="recipe.joint_colour" :value="$recipe['joint_colour']" id="joint-colour" />
                        <x-ui.studio-slider :label="__('Roughness')" model="recipe.roughness" :value="$recipe['roughness']" />
                        <x-ui.studio-slider :label="__('Tone variation')" model="recipe.tone_variation" :value="$recipe['tone_variation']" />
                    @elseif ($generator === 'timber')
                        <div class="ui-studio-measure-grid">
                            <x-ui.studio-measure :label="__('Board width')" model="recipe.board_width_mm" :value="$recipe['board_width_mm']" min="1" />
                            <x-ui.studio-measure :label="__('Board length')" model="recipe.board_length_mm" :value="$recipe['board_length_mm']" min="1" />
                            <x-ui.studio-measure :label="__('Joint')" model="recipe.joint_mm" :value="$recipe['joint_mm']" />
                        </div>
                        <label class="ui-studio-switch"><input type="checkbox" wire:model.live="recipe.stagger" /><span></span><strong>{{ __('Stagger boards') }}</strong></label>
                        <x-ui.studio-slider :label="__('Grain strength')" model="recipe.grain_strength" :value="$recipe['grain_strength']" />
                        <x-ui.studio-slider :label="__('Roughness')" model="recipe.roughness" :value="$recipe['roughness']" />
                    @elseif ($generator === 'terrazzo')
                        <x-ui.studio-colour :label="__('Matrix colour')" model="recipe.matrix_colour" :value="$recipe['matrix_colour']" id="matrix-colour" />
                        <x-ui.studio-measure :label="__('Chip size')" model="recipe.chip_size_mm" :value="$recipe['chip_size_mm']" min="0.1" />
                        <x-ui.studio-slider :label="__('Chip density')" model="recipe.density" :value="$recipe['density']" />
                        <x-ui.studio-slider :label="__('Roughness')" model="recipe.roughness" :value="$recipe['roughness']" />
                        <x-ui.studio-measure :label="__('Chip depth')" model="recipe.chip_depth_mm" :value="$recipe['chip_depth_mm']" />
                    @else
                        <div class="ui-studio-colour-pair">
                            <x-ui.studio-colour :label="__('Warp')" model="recipe.warp_colour" :value="$recipe['warp_colour']" id="warp-colour" />
                            <x-ui.studio-colour :label="__('Weft')" model="recipe.weft_colour" :value="$recipe['weft_colour']" id="weft-colour" />
                        </div>
                        <x-ui.studio-measure :label="__('Thread width')" model="recipe.thread_mm" :value="$recipe['thread_mm']" min="0.1" />
                        <label class="ui-studio-switch"><input type="checkbox" wire:model.live="recipe.basket" /><span></span><strong>{{ __('Basket weave') }}</strong></label>
                        <x-ui.studio-slider :label="__('Roughness')" model="recipe.roughness" :value="$recipe['roughness']" />
                        <x-ui.studio-measure :label="__('Relief depth')" model="recipe.depth_mm" :value="$recipe['depth_mm']" />
                    @endif
                </div>

                @php $paletteKey = match ($generator) { 'masonry' => 'unit_colours', 'timber' => 'colours', 'terrazzo' => 'chip_colours', default => null }; @endphp
                @if ($paletteKey)
                    <div class="ui-studio-palette">
                        <div class="ui-studio-palette__head">
                            <div><strong>{{ __('Material palette') }}</strong><small>{{ __('Click a swatch to edit; enter hex for exact supplier colour.') }}</small></div>
                            <button type="button" wire:click="addPaletteColour('{{ $paletteKey }}')" @disabled(count($recipe[$paletteKey] ?? []) >= 8)>+ {{ __('Add') }}</button>
                        </div>
                        <div class="ui-studio-palette__swatches">
                            @foreach (($recipe[$paletteKey] ?? []) as $index => $colour)
                                <div class="ui-studio-palette__item" style="--studio-swatch: {{ $colour }}" wire:key="palette-{{ $paletteKey }}-{{ $index }}">
                                    <label title="{{ __('Edit colour :number', ['number' => $index + 1]) }}">
                                        <input type="color" wire:model.live.debounce.350ms="recipe.{{ $paletteKey }}.{{ $index }}" />
                                        <span><b>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</b></span>
                                    </label>
                                    <input type="text" value="{{ $colour }}" wire:model.live.debounce.450ms="recipe.{{ $paletteKey }}.{{ $index }}" maxlength="7" aria-label="{{ __('Colour :number hex value', ['number' => $index + 1]) }}" />
                                    <button type="button" wire:click="removePaletteColour('{{ $paletteKey }}', {{ $index }})" aria-label="{{ __('Remove colour :number', ['number' => $index + 1]) }}" @disabled(count($recipe[$paletteKey] ?? []) <= 1)>×</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </aside>

            <main class="ui-studio-canvas">
                <div class="ui-studio-canvas__bar">
                    <div>
                        <strong>{{ __('Live material') }}</strong>
                        <span>{{ number_format($width_mm, 1) }} × {{ number_format($height_mm, 1) }} mm repeat</span>
                    </div>
                    <div class="ui-studio-canvas__tools">
                        <span>{{ __('Drag to rotate · scroll to zoom') }}</span>
                        <button type="button" wire:click="preview" data-test="preview-recipe">{{ __('Refresh') }}</button>
                    </div>
                </div>

                @if ($previewError !== '')
                    <div class="ui-studio-preview-error"><strong>{{ __('Preview unavailable') }}</strong><span>{{ $previewError }}</span></div>
                @endif

                @if ($previewMaps === [])
                    <div class="ui-studio-canvas__empty">
                        <span></span>
                        <strong>{{ __('Waiting for a valid recipe') }}</strong>
                        <p>{{ __('Complete the highlighted dimensions and the material will render here automatically.') }}</p>
                    </div>
                @else
                    @php
                        $previewSet = ['preview' => [
                            'key' => 'studio-'.$previewRevision,
                            'tile_mm' => max($width_mm, $height_mm),
                            'finish' => match ($generator) { 'paint' => 'matte', 'timber' => 'wood', 'textile' => 'textile', default => 'default' },
                            ...$previewMaps,
                        ]];
                    @endphp
                    <div
                        class="ui-viewer ui-studio-live-viewer"
                        wire:key="studio-preview-{{ $previewRevision }}"
                        x-data="materialViewer(@js(['sets' => $previewSet, 'objectSizeMm' => max($width_mm, $height_mm), 'framing' => 1.28, 'verticalBias' => 0.04]))"
                        x-effect="show('preview')"
                        data-test="studio-live-preview"
                    >
                        <div class="ui-viewer__stage" x-ref="stage" wire:ignore>
                            <x-ui.material-map-inspector tileable />
                        </div>
                    </div>
                @endif

                <div class="ui-studio-canvas__foot">
                    <span><i></i>{{ __('Preview uses the same deterministic engine as the production bake') }}</span>
                    <code>seed {{ $seed }}</code>
                </div>
                <div class="ui-studio-loading" wire:loading.flex>
                    <span></span><strong>{{ __('Rebuilding preview') }}</strong>
                </div>
            </main>

            <aside class="ui-studio-properties ui-studio-properties--output">
                <details open>
                    <summary><span>02</span><strong>{{ __('Scale & output') }}</strong><i></i></summary>
                    <div class="ui-studio-details">
                        <div class="ui-studio-measure-grid">
                            <x-ui.studio-measure :label="__('Repeat width')" model="width_mm" :value="$width_mm" min="1" />
                            <x-ui.studio-measure :label="__('Repeat height')" model="height_mm" :value="$height_mm" min="1" />
                        </div>
                        <label class="ui-studio-select"><span>{{ __('Production resolution') }}</span><select wire:model="resolution">@foreach ([512, 1024, 2048, 4096] as $size)<option value="{{ $size }}">{{ $size >= 1024 ? ($size / 1024).'K' : $size.' px' }}</option>@endforeach</select></label>
                        <label class="ui-studio-seed"><span>{{ __('Variation seed') }}</span><span><input type="number" min="0" wire:model.live.debounce.450ms="seed" /><button type="button" wire:click="randomizeSeed" title="{{ __('Generate another deterministic variation') }}">↻</button></span></label>
                        <p class="ui-studio-hint">{{ __('Patterned recipes only accept complete repeats, so exported maps remain seamless.') }}</p>
                    </div>
                </details>

                <details open>
                    <summary><span>03</span><strong>{{ __('Library destination') }}</strong><i></i></summary>
                    <div class="ui-studio-details">
                        <div class="ui-segment ui-studio-mode" role="group" aria-label="{{ __('Recipe destination') }}">
                            <button type="button" wire:click="$set('mode', 'new')" @class(['is-active' => $mode === 'new'])>{{ __('New') }}</button>
                            <button type="button" wire:click="$set('mode', 'existing')" @class(['is-active' => $mode === 'existing'])>{{ __('Existing') }}</button>
                        </div>

                        @if ($mode === 'new')
                            <x-ui.field :label="__('Material name')" for="studio-name" required :error="$errors->first('name')"><input id="studio-name" class="ui-input" wire:model="name" placeholder="Warm white paint" /></x-ui.field>
                            <x-ui.field :label="__('Category')" for="studio-category" required :error="$errors->first('category_id')"><select id="studio-category" class="ui-select" wire:model="category_id"><option value="">{{ __('Choose…') }}</option>@foreach ($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Supplier')" for="studio-supplier"><select id="studio-supplier" class="ui-select" wire:model="supplier_id"><option value="">{{ __('In-house') }}</option>@foreach ($this->suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Or new supplier')" for="studio-new-supplier"><input id="studio-new-supplier" class="ui-input" wire:model="new_supplier" /></x-ui.field>
                            <div class="ui-studio-field-pair">
                                <x-ui.field :label="__('Collection')" for="studio-collection"><input id="studio-collection" class="ui-input" wire:model="collection" /></x-ui.field>
                                <x-ui.field :label="__('Product code')" for="studio-product-code"><input id="studio-product-code" class="ui-input" wire:model="supplier_product_code" /></x-ui.field>
                            </div>
                        @else
                            <x-ui.field :label="__('Material')" for="studio-material" required :error="$errors->first('materialCode')"><select id="studio-material" class="ui-select" wire:model.live="materialCode"><option value="">{{ __('Choose…') }}</option>@foreach ($this->materials as $material)<option value="{{ $material->code }}">{{ $material->name }} · {{ $material->code }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Variant')" for="studio-variant" required :error="$errors->first('variantCode')"><select id="studio-variant" class="ui-select" wire:model.live="variantCode" @disabled($this->selectedMaterial === null)><option value="">{{ __('Choose…') }}</option>@foreach ($this->variants as $variant)<option value="{{ $variant->code }}">{{ $variant->name }}{{ $variant->definition ? ' · recipe' : '' }}</option>@endforeach<option value="__new">{{ __('+ Add a new variant') }}</option></select></x-ui.field>
                        @endif

                        @if ($mode === 'new' || $variantCode === '__new')
                            <div class="ui-studio-field-pair">
                                <x-ui.field :label="__('Colourway')" for="studio-colourway" :error="$errors->first('colourway')"><input id="studio-colourway" class="ui-input" wire:model="colourway" placeholder="Natural" /></x-ui.field>
                                <x-ui.field :label="__('Supplier code')" for="studio-colourway-code"><input id="studio-colourway-code" class="ui-input" wire:model="colourway_code" /></x-ui.field>
                            </div>
                        @endif
                    </div>
                </details>

                <div class="ui-studio-publish">
                    <div><span></span><p><strong>{{ __('Saved as a candidate') }}</strong>{{ __('Nothing is approved or published automatically.') }}</p></div>
                    <x-ui.button type="submit" data-test="save-recipe">{{ __('Save recipe & bake') }}</x-ui.button>
                    <x-ui.button :href="route('materials.index')" variant="ghost" size="sm" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                </div>
            </aside>
        </div>
    </form>
</section>
