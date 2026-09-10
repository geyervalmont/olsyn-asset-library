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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Material Studio')] class extends Component {
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

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        if ($this->materialCode !== '') {
            $this->mode = 'existing';
        }

        $this->mode = in_array($this->mode, ['new', 'existing'], true) ? $this->mode : 'new';
        $this->recipe = ProceduralRecipes::defaults($this->generator);
        $this->loadExistingDefinition();
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
        }
    }

    public function removePaletteColour(string $key, int $index): void
    {
        $values = is_array($this->recipe[$key] ?? null) ? $this->recipe[$key] : [];

        if (count($values) > 1 && array_key_exists($index, $values)) {
            unset($values[$index]);
            $this->recipe[$key] = array_values($values);
        }
    }

    public function preview(ProceduralBaker $baker): void
    {
        $this->validate($this->recipeRules());

        if (! $this->validateRepeat()) {
            return;
        }

        $this->previewError = '';

        if (! $baker->available()) {
            $this->previewError = __('The USD toolbox is not installed on this server.');

            return;
        }

        try {
            $bake = $baker->bake($this->definitionPayload(384));
            $this->previewMaps = [];

            foreach ($bake->assets as $asset) {
                if (str_starts_with($asset->mimeType, 'image/')) {
                    $this->previewMaps[$asset->role] = 'data:'.$asset->mimeType.';base64,'.base64_encode($asset->contents);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->previewError = $exception->getMessage();
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

    private function validateRepeat(): bool
    {
        $valid = true;

        if ($this->generator === 'masonry') {
            $unitWidth = (float) ($this->recipe['unit_width_mm'] ?? 0);
            $unitHeight = (float) ($this->recipe['unit_height_mm'] ?? 0);
            $joint = (float) ($this->recipe['joint_mm'] ?? 0);

            if ($joint >= min($unitWidth, $unitHeight)) {
                $this->addError('recipe.joint_mm', __('Joint must be smaller than both unit dimensions.'));
                $valid = false;
            }

            $valid = $this->aligned('width_mm', $this->width_mm, $unitWidth + $joint, 1) && $valid;
            $multiple = match ($this->recipe['bond'] ?? 'stack') { 'quarter' => 4, 'running' => 2, default => 1 };
            $valid = $this->aligned('height_mm', $this->height_mm, $unitHeight + $joint, $multiple) && $valid;
        } elseif ($this->generator === 'timber') {
            $joint = (float) ($this->recipe['joint_mm'] ?? 0);
            $valid = $this->aligned('width_mm', $this->width_mm, (float) ($this->recipe['board_length_mm'] ?? 0) + $joint, 1) && $valid;
            $valid = $this->aligned('height_mm', $this->height_mm, (float) ($this->recipe['board_width_mm'] ?? 0) + $joint, ($this->recipe['stagger'] ?? false) ? 2 : 1) && $valid;
        } elseif ($this->generator === 'textile') {
            $pitch = (float) ($this->recipe['thread_mm'] ?? 0) * 2;
            $valid = $this->aligned('width_mm', $this->width_mm, $pitch, 1) && $valid;
            $valid = $this->aligned('height_mm', $this->height_mm, $pitch, 1) && $valid;
        }

        return $valid;
    }

    private function aligned(string $field, float $dimension, float $pitch, int $multiple): bool
    {
        if ($pitch <= 0) {
            return false;
        }

        $repeats = $dimension / $pitch;
        $count = max($multiple, (int) round($repeats / $multiple) * $multiple);

        if (abs($repeats - round($repeats)) <= 0.0001 && ((int) round($repeats)) % $multiple === 0) {
            return true;
        }

        $this->addError($field, __('Use :size mm for a complete repeat at these settings.', ['size' => round($pitch * $count, 3)]));

        return false;
    }

    private function clearPreview(): void
    {
        $this->previewMaps = [];
        $this->previewError = '';
    }
}; ?>

<section>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Authoring') }}</x-ui.eyebrow>
            <h1>{{ __('Material Studio') }}</h1>
            <p class="ui-page-head__lede">{{ __('Build tileable, real-scale materials from editable recipes. Preview quickly, then bake an immutable candidate for review and every downstream target.') }}</p>
        </div>
        <div class="ui-page-head__actions">
            <x-ui.button :href="route('materials.create')" variant="quiet" wire:navigate>{{ __('Import image maps') }}</x-ui.button>
        </div>
    </div>

    <form wire:submit="save" class="ui-studio">
        <div class="ui-studio__controls">
            <x-ui.panel>
                <div class="ui-form">
                    <div class="ui-segment" role="group" aria-label="{{ __('Recipe destination') }}" style="width: fit-content">
                        <button type="button" wire:click="$set('mode', 'new')" @class(['is-active' => $mode === 'new'])>{{ __('New material') }}</button>
                        <button type="button" wire:click="$set('mode', 'existing')" @class(['is-active' => $mode === 'existing'])>{{ __('Improve existing') }}</button>
                    </div>

                    @if ($mode === 'new')
                        <div class="ui-form ui-form--2">
                            <x-ui.field :label="__('Name')" for="studio-name" required :error="$errors->first('name')"><input id="studio-name" class="ui-input" wire:model="name" placeholder="Warm white paint" /></x-ui.field>
                            <x-ui.field :label="__('Category')" for="studio-category" required :error="$errors->first('category_id')"><select id="studio-category" class="ui-select" wire:model="category_id"><option value="">{{ __('Choose…') }}</option>@foreach ($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Supplier')" for="studio-supplier"><select id="studio-supplier" class="ui-select" wire:model="supplier_id"><option value="">{{ __('In-house') }}</option>@foreach ($this->suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Or new supplier')" for="studio-new-supplier"><input id="studio-new-supplier" class="ui-input" wire:model="new_supplier" /></x-ui.field>
                            <x-ui.field :label="__('Collection')" for="studio-collection"><input id="studio-collection" class="ui-input" wire:model="collection" /></x-ui.field>
                            <x-ui.field :label="__('Product code')" for="studio-product-code"><input id="studio-product-code" class="ui-input" wire:model="supplier_product_code" /></x-ui.field>
                        </div>
                    @else
                        <div class="ui-form ui-form--2">
                            <x-ui.field :label="__('Material')" for="studio-material" required :error="$errors->first('materialCode')"><select id="studio-material" class="ui-select" wire:model.live="materialCode"><option value="">{{ __('Choose…') }}</option>@foreach ($this->materials as $material)<option value="{{ $material->code }}">{{ $material->name }} · {{ $material->code }}</option>@endforeach</select></x-ui.field>
                            <x-ui.field :label="__('Variant')" for="studio-variant" required :error="$errors->first('variantCode')"><select id="studio-variant" class="ui-select" wire:model.live="variantCode" @disabled($this->selectedMaterial === null)><option value="">{{ __('Choose…') }}</option>@foreach ($this->variants as $variant)<option value="{{ $variant->code }}">{{ $variant->name }}{{ $variant->definition ? ' · recipe' : '' }}</option>@endforeach<option value="__new">{{ __('+ Add a new variant') }}</option></select></x-ui.field>
                        </div>
                    @endif

                    @if ($mode === 'new' || $variantCode === '__new')
                        <div class="ui-form ui-form--2">
                            <x-ui.field :label="__('Colourway')" for="studio-colourway" :error="$errors->first('colourway')"><input id="studio-colourway" class="ui-input" wire:model="colourway" placeholder="Natural" /></x-ui.field>
                            <x-ui.field :label="__('Supplier colour code')" for="studio-colourway-code"><input id="studio-colourway-code" class="ui-input" wire:model="colourway_code" /></x-ui.field>
                        </div>
                    @endif
                </div>
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>{{ __('Recipe') }}</h3><p>{{ __('Choose a system, then tune physical parameters.') }}</p></div></div>
                <div class="ui-recipe-tabs">
                    @foreach ($this->generators() as $slug => $label)
                        <button type="button" wire:click="chooseGenerator('{{ $slug }}')" @class(['is-active' => $generator === $slug])>{{ $label }}</button>
                    @endforeach
                </div>

                <div class="ui-form ui-form--2" style="margin-top: 16px">
                    @if ($generator === 'paint')
                        <x-ui.field :label="__('Colour')" for="paint-colour" :error="$errors->first('recipe.colour')"><input id="paint-colour" class="ui-colour" type="color" wire:model="recipe.colour" /></x-ui.field>
                        <x-ui.field :label="__('Roughness')" for="paint-rough"><input id="paint-rough" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.roughness" /></x-ui.field>
                        <x-ui.field :label="__('Tone variation')" for="paint-var"><input id="paint-var" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.variation" /></x-ui.field>
                        <x-ui.field :label="__('Surface depth')" for="paint-depth"><input id="paint-depth" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.texture_depth" /></x-ui.field>
                    @elseif ($generator === 'masonry')
                        <x-ui.field :label="__('Unit width (mm)')" for="unit-w"><input id="unit-w" class="ui-input" type="number" step="0.1" wire:model="recipe.unit_width_mm" /></x-ui.field>
                        <x-ui.field :label="__('Unit height (mm)')" for="unit-h"><input id="unit-h" class="ui-input" type="number" step="0.1" wire:model="recipe.unit_height_mm" /></x-ui.field>
                        <x-ui.field :label="__('Joint (mm)')" for="unit-j"><input id="unit-j" class="ui-input" type="number" step="0.1" wire:model="recipe.joint_mm" /></x-ui.field>
                        <x-ui.field :label="__('Bond')" for="unit-b"><select id="unit-b" class="ui-select" wire:model="recipe.bond"><option value="stack">{{ __('Stack') }}</option><option value="running">{{ __('Running') }}</option><option value="quarter">{{ __('Quarter') }}</option></select></x-ui.field>
                        <x-ui.field :label="__('Joint colour')" for="joint-colour"><input id="joint-colour" class="ui-colour" type="color" wire:model="recipe.joint_colour" /></x-ui.field>
                        <x-ui.field :label="__('Edge depth (mm)')" for="edge-depth"><input id="edge-depth" class="ui-input" type="number" min="0" step="0.1" wire:model="recipe.edge_depth_mm" /></x-ui.field>
                        <x-ui.field :label="__('Roughness')" for="masonry-rough"><input id="masonry-rough" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.roughness" /></x-ui.field>
                        <x-ui.field :label="__('Tone variation')" for="masonry-var"><input id="masonry-var" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.tone_variation" /></x-ui.field>
                    @elseif ($generator === 'timber')
                        <x-ui.field :label="__('Board width (mm)')" for="board-w"><input id="board-w" class="ui-input" type="number" step="0.1" wire:model="recipe.board_width_mm" /></x-ui.field>
                        <x-ui.field :label="__('Board length (mm)')" for="board-l"><input id="board-l" class="ui-input" type="number" step="0.1" wire:model="recipe.board_length_mm" /></x-ui.field>
                        <x-ui.field :label="__('Joint (mm)')" for="board-j"><input id="board-j" class="ui-input" type="number" min="0" step="0.1" wire:model="recipe.joint_mm" /></x-ui.field>
                        <x-ui.field :label="__('Grain strength')" for="grain"><input id="grain" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.grain_strength" /></x-ui.field>
                        <x-ui.field :label="__('Roughness')" for="timber-rough"><input id="timber-rough" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.roughness" /></x-ui.field>
                        <label class="ui-check"><input type="checkbox" wire:model="recipe.stagger" /> {{ __('Stagger boards') }}</label>
                    @elseif ($generator === 'terrazzo')
                        <x-ui.field :label="__('Matrix colour')" for="matrix-colour"><input id="matrix-colour" class="ui-colour" type="color" wire:model="recipe.matrix_colour" /></x-ui.field>
                        <x-ui.field :label="__('Chip size (mm)')" for="chip-size"><input id="chip-size" class="ui-input" type="number" step="0.1" wire:model="recipe.chip_size_mm" /></x-ui.field>
                        <x-ui.field :label="__('Chip density')" for="chip-density"><input id="chip-density" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.density" /></x-ui.field>
                        <x-ui.field :label="__('Roughness')" for="terrazzo-rough"><input id="terrazzo-rough" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.roughness" /></x-ui.field>
                        <x-ui.field :label="__('Chip depth (mm)')" for="chip-depth"><input id="chip-depth" class="ui-input" type="number" min="0" step="0.1" wire:model="recipe.chip_depth_mm" /></x-ui.field>
                    @else
                        <x-ui.field :label="__('Warp colour')" for="warp-colour"><input id="warp-colour" class="ui-colour" type="color" wire:model="recipe.warp_colour" /></x-ui.field>
                        <x-ui.field :label="__('Weft colour')" for="weft-colour"><input id="weft-colour" class="ui-colour" type="color" wire:model="recipe.weft_colour" /></x-ui.field>
                        <x-ui.field :label="__('Thread (mm)')" for="thread"><input id="thread" class="ui-input" type="number" step="0.1" wire:model="recipe.thread_mm" /></x-ui.field>
                        <x-ui.field :label="__('Roughness')" for="textile-rough"><input id="textile-rough" class="ui-input" type="number" min="0" max="1" step="0.01" wire:model="recipe.roughness" /></x-ui.field>
                        <x-ui.field :label="__('Depth (mm)')" for="textile-depth"><input id="textile-depth" class="ui-input" type="number" min="0" step="0.1" wire:model="recipe.depth_mm" /></x-ui.field>
                        <label class="ui-check"><input type="checkbox" wire:model="recipe.basket" /> {{ __('Basket weave') }}</label>
                    @endif
                </div>

                @php $paletteKey = match ($generator) { 'masonry' => 'unit_colours', 'timber' => 'colours', 'terrazzo' => 'chip_colours', default => null }; @endphp
                @if ($paletteKey)
                    <div class="ui-palette">
                        <strong>{{ __('Palette') }}</strong>
                        @foreach (($recipe[$paletteKey] ?? []) as $index => $colour)
                            <span wire:key="palette-{{ $paletteKey }}-{{ $index }}"><input class="ui-colour" type="color" wire:model="recipe.{{ $paletteKey }}.{{ $index }}" /><button type="button" wire:click="removePaletteColour('{{ $paletteKey }}', {{ $index }})" aria-label="{{ __('Remove colour') }}">×</button></span>
                        @endforeach
                        <button type="button" wire:click="addPaletteColour('{{ $paletteKey }}')">{{ __('+ colour') }}</button>
                    </div>
                @endif
            </x-ui.panel>

            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>{{ __('Output') }}</h3><p>{{ __('Real-world repeat and production resolution.') }}</p></div></div>
                <div class="ui-form ui-form--2">
                    <x-ui.field :label="__('Width (mm)')" for="output-w" :error="$errors->first('width_mm')"><input id="output-w" class="ui-input" type="number" min="1" step="0.1" wire:model="width_mm" /></x-ui.field>
                    <x-ui.field :label="__('Height (mm)')" for="output-h" :error="$errors->first('height_mm')"><input id="output-h" class="ui-input" type="number" min="1" step="0.1" wire:model="height_mm" /></x-ui.field>
                    <x-ui.field :label="__('Resolution')" for="output-resolution"><select id="output-resolution" class="ui-select" wire:model="resolution">@foreach ([512, 1024, 2048, 4096] as $size)<option value="{{ $size }}">{{ $size >= 1024 ? ($size / 1024).'K' : $size.' px' }}</option>@endforeach</select></x-ui.field>
                    <x-ui.field :label="__('Seed')" for="output-seed"><input id="output-seed" class="ui-input" type="number" min="0" wire:model="seed" /></x-ui.field>
                </div>
            </x-ui.panel>
        </div>

        <div class="ui-studio__preview">
            <x-ui.panel>
                <div class="ui-panel__heading"><div><h3>{{ __('Preview') }}</h3><p>{{ __('A fast 384 px bake using the production engine.') }}</p></div><x-ui.button type="button" wire:click="preview" variant="secondary" size="sm" data-test="preview-recipe">{{ __('Update preview') }}</x-ui.button></div>
                @if ($previewError !== '')<div class="ui-notice"><span class="ui-notice__rule"></span><div><strong>{{ __('Preview unavailable') }}</strong><p>{{ $previewError }}</p></div></div>@endif
                @if ($previewMaps === [])
                    <div class="ui-studio__empty"><span></span><p>{{ __('Set the recipe, then update the preview.') }}</p></div>
                @else
                    <div class="ui-studio__maps">
                        @foreach ($previewMaps as $role => $url)
                            <figure><img src="{{ $url }}" alt="{{ str_replace('_', ' ', $role) }}" /><figcaption>{{ str_replace('_', ' ', $role) }}</figcaption></figure>
                        @endforeach
                    </div>
                @endif
            </x-ui.panel>

            <div class="ui-notice"><span class="ui-notice__rule"></span><div><strong>{{ __('Nothing publishes automatically') }}</strong><p>{{ __('Saving keeps the recipe editable and queues a deterministic candidate. Review it on the material page before publishing it to the drive, Revit, or Omniverse.') }}</p></div></div>
            <div class="ui-actions">
                <x-ui.button type="submit" data-test="save-recipe">{{ __('Save recipe & bake candidate') }}</x-ui.button>
                <x-ui.button :href="route('materials.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</x-ui.button>
            </div>
        </div>
    </form>
</section>
