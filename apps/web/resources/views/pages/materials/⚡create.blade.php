<?php

use App\Actions\Materials\CreateMaterial;
use App\Actions\Materials\AddVariant;
use App\Actions\Materials\ImportMaterialMaps;
use App\Models\Category;
use App\Models\MapRole;
use App\Models\Material;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Material Import')] class extends Component {
    use WithFileUploads;

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

    public string $source_id = '';

    public string $collection = '';

    public string $supplier_product_code = '';

    public string $description = '';

    public string $tile_width_mm = '';

    public string $tile_height_mm = '';

    public string $colourway = '';

    public string $colourway_code = '';

    public string $finish = '';

    public string $normal_convention = 'opengl';

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $maps = [];

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $auto_maps = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        if ($this->materialCode !== '') {
            $this->mode = 'existing';
        }

        $this->mode = in_array($this->mode, ['new', 'existing'], true) ? $this->mode : 'new';
    }

    #[Computed]
    public function materials(): Collection
    {
        return Material::query()->visibleTo(auth()->user())->orderBy('name')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function selectedMaterial(): ?Material
    {
        if ($this->materialCode === '') {
            return null;
        }

        $material = Material::resolveCode($this->materialCode);

        return $material !== null && $material->isVisibleTo(auth()->user()) ? $material : null;
    }

    #[Computed]
    public function existingVariants(): Collection
    {
        return $this->selectedMaterial?->variants()->orderBy('position')->get() ?? collect();
    }

    public function updatedMode(): void
    {
        $this->mode = in_array($this->mode, ['new', 'existing'], true) ? $this->mode : 'new';
        $this->resetValidation();
    }

    public function updatedMaterialCode(): void
    {
        $this->variantCode = '';
        unset($this->selectedMaterial, $this->existingVariants);
    }

    public function updatedAutoMaps(): void
    {
        foreach ($this->auto_maps as $upload) {
            $name = strtolower(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME));
            $role = match (true) {
                preg_match('/(^|[_ .-])(normal|nor|nrm)([_ .-]|$)/', $name) === 1 => 'normal',
                preg_match('/(^|[_ .-])(roughness|rough|rgh)([_ .-]|$)/', $name) === 1 => 'roughness',
                preg_match('/(^|[_ .-])(metallic|metalness|metal)([_ .-]|$)/', $name) === 1 => 'metallic',
                preg_match('/(^|[_ .-])(height|displacement|disp)([_ .-]|$)/', $name) === 1 => 'height',
                preg_match('/(^|[_ .-])(ambient.?occlusion|ao)([_ .-]|$)/', $name) === 1 => 'ao',
                preg_match('/(^|[_ .-])(opacity|alpha|transparency)([_ .-]|$)/', $name) === 1 => 'opacity',
                preg_match('/(^|[_ .-])(base.?colou?r|albedo|diffuse|diff)([_ .-]|$)/', $name) === 1 => 'base_color',
                default => null,
            };

            if ($role === null) {
                $this->addError('auto_maps', __('Could not identify :file. Add it to a named map field below.', ['file' => $upload->getClientOriginalName()]));

                continue;
            }

            $this->maps[$role] = $upload;
        }

        $this->auto_maps = [];
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
    public function sources(): Collection
    {
        return Source::query()->orderBy('name')->get();
    }

    #[Computed]
    public function roles(): Collection
    {
        return MapRole::query()
            ->whereIn('slug', ['base_color', 'normal', 'roughness', 'metallic', 'height', 'ao', 'opacity'])
            ->orderBy('sort_order')
            ->get();
    }

    public function save(CreateMaterial $createMaterial, AddVariant $addVariant, ImportMaterialMaps $import): void
    {
        $rules = [
            'mode' => ['required', 'in:new,existing'],
            'materialCode' => [$this->mode === 'existing' ? 'required' : 'nullable', 'string', 'max:120'],
            'variantCode' => [$this->mode === 'existing' ? 'required' : 'nullable', 'string', 'max:120'],
            'name' => [$this->mode === 'new' ? 'required' : 'nullable', 'string', 'max:120'],
            'category_id' => [$this->mode === 'new' ? 'required' : 'nullable', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'new_supplier' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'exists:sources,id'],
            'collection' => ['nullable', 'string', 'max:120'],
            'supplier_product_code' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tile_width_mm' => ['nullable', 'numeric', 'min:0'],
            'tile_height_mm' => ['nullable', 'numeric', 'min:0'],
            'colourway' => [$this->mode === 'existing' && $this->variantCode === '__new' ? 'required' : 'nullable', 'string', 'max:120'],
            'colourway_code' => ['nullable', 'string', 'max:120'],
            'finish' => ['nullable', 'string', 'max:120'],
            'normal_convention' => ['required', 'in:opengl,directx'],
            'auto_maps' => ['array'],
            'auto_maps.*' => ['image', 'max:102400'],
            'maps' => ['array'],
            'maps.*' => ['nullable', 'image', 'max:102400'],
        ];

        $validated = $this->validate($rules);

        if (! collect($this->maps)->contains(fn (mixed $map): bool => $map instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)) {
            $this->addError('maps', __('Add at least one recognised material map before saving.'));

            return;
        }

        $user = auth()->user();
        $source = $validated['source_id'] !== '' ? Source::query()->find($validated['source_id']) : null;

        if ($this->mode === 'existing') {
            $material = $this->selectedMaterial;

            if ($material === null) {
                $this->addError('materialCode', __('Choose a material you can access.'));

                return;
            }

            $variant = $this->variantCode === '__new'
                ? $addVariant->handle($material, array_filter([
                    'colourway' => $validated['colourway'] !== '' ? ['value' => $validated['colourway'], 'supplier_code' => $validated['colourway_code'] ?: null] : null,
                    'finish' => $validated['finish'] !== '' ? $validated['finish'] : null,
                ]))
                : $material->variants()->where('code', $this->variantCode)->first();

            if ($variant === null) {
                $this->addError('variantCode', __('Choose an existing variant or create a new one.'));

                return;
            }

            $import->handle($variant, $this->maps, $user, $source, $validated['normal_convention'], 'Improvement uploaded through Material Import.');
        } else {
            $material = DB::transaction(function () use ($validated, $user, $createMaterial, $source): Material {
                $supplierId = $validated['supplier_id'] !== '' ? (int) $validated['supplier_id'] : null;

                if ($supplierId === null && trim($validated['new_supplier'] ?? '') !== '') {
                    $supplierId = (int) Supplier::query()->firstOrCreate(['name' => trim($validated['new_supplier'])])->getKey();
                }

                $attributes = array_filter([
                    'colourway' => $validated['colourway'] !== '' ? ['value' => $validated['colourway'], 'supplier_code' => $validated['colourway_code'] ?: null] : null,
                    'finish' => $validated['finish'] !== '' ? $validated['finish'] : null,
                ]);

                return $createMaterial->handle([
                    'name' => $validated['name'],
                    'category_id' => (int) $validated['category_id'],
                    'supplier_id' => $supplierId,
                    'source_id' => $source?->getKey(),
                    'collection' => $validated['collection'] ?: null,
                    'supplier_product_code' => $validated['supplier_product_code'] ?: null,
                    'description' => $validated['description'] ?: null,
                    'tile_width_mm' => $validated['tile_width_mm'] !== '' ? $validated['tile_width_mm'] : null,
                    'tile_height_mm' => $validated['tile_height_mm'] !== '' ? $validated['tile_height_mm'] : null,
                    'contributed_by_tenant_id' => Tenant::current()?->getKey(),
                    'contributed_by_user_id' => $user?->getKey(),
                ], [['attributes' => $attributes]]);
            });

            $variant = $material->variants->first();

            if ($variant !== null) {
                $import->handle($variant, $this->maps, $user, $source, $validated['normal_convention']);
            }
        }

        $this->redirect(route('materials.show', $material), navigate: true);
    }
}; ?>

<section style="max-width: 880px">
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Library') }}</x-ui.eyebrow>
            <h1>{{ __('Material Import') }}</h1>
            <p class="ui-page-head__lede">{{ __('Create a catalog record or improve an existing colourway. Every upload becomes a traceable candidate, so approved files are never silently replaced.') }}</p>
        </div>
    </div>

    <x-ui.panel>
        <form wire:submit="save" class="ui-form">
            <div class="ui-segment" role="group" aria-label="{{ __('Import mode') }}" style="width: fit-content">
                <button type="button" wire:click="$set('mode', 'new')" @class(['is-active' => $mode === 'new']) data-test="mode-new">{{ __('New material') }}</button>
                <button type="button" wire:click="$set('mode', 'existing')" @class(['is-active' => $mode === 'existing']) data-test="mode-existing">{{ __('Improve existing') }}</button>
            </div>

            @if ($mode === 'existing')
                <div class="ui-form ui-form--2" data-test="existing-material-fields">
                    <x-ui.field :label="__('Material')" for="materialCode" required :error="$errors->first('materialCode')">
                        <select id="materialCode" class="ui-select" wire:model.live="materialCode" data-test="existing-material">
                            <option value="">{{ __('Choose a material…') }}</option>
                            @foreach ($this->materials as $material)
                                <option value="{{ $material->code }}">{{ $material->name }} · {{ $material->code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('Colourway / variant')" for="variantCode" required :error="$errors->first('variantCode')">
                        <select id="variantCode" class="ui-select" wire:model.live="variantCode" data-test="existing-variant" @disabled($this->selectedMaterial === null)>
                            <option value="">{{ __('Choose a variant…') }}</option>
                            @foreach ($this->existingVariants as $variant)
                                <option value="{{ $variant->code }}">{{ $variant->name }} · {{ $variant->code }}</option>
                            @endforeach
                            <option value="__new">{{ __('+ Add a new variant') }}</option>
                        </select>
                    </x-ui.field>
                </div>
                @if ($variantCode === '__new')
                    <div class="ui-form ui-form--2">
                        <x-ui.field :label="__('New colourway')" for="colourway" required :error="$errors->first('colourway')">
                            <input id="colourway" class="ui-input" wire:model="colourway" placeholder="Ashen" data-test="colourway" />
                        </x-ui.field>
                        <x-ui.field :label="__('Supplier colour code')" for="colourway_code">
                            <input id="colourway_code" class="ui-input" wire:model="colourway_code" placeholder="634014001" />
                        </x-ui.field>
                    </div>
                @endif
            @else
            <div class="ui-form ui-form--2" data-test="new-material-fields">
                <x-ui.field :label="__('Name')" for="name" required :error="$errors->first('name')">
                    <input id="name" class="ui-input" wire:model="name" placeholder="Academix" data-test="name" />
                </x-ui.field>
                <x-ui.field :label="__('Category')" for="category_id" required :error="$errors->first('category_id')">
                    <select id="category_id" class="ui-select" wire:model="category_id" data-test="category">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }} ({{ $category->code }})</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('Supplier')" for="supplier_id" :error="$errors->first('supplier_id')">
                    <select id="supplier_id" class="ui-select" wire:model="supplier_id" data-test="supplier">
                        <option value="">{{ __('In-house (OPAL)') }}</option>
                        @foreach ($this->suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('Or a new supplier')" for="new_supplier" :error="$errors->first('new_supplier')">
                    <input id="new_supplier" class="ui-input" wire:model="new_supplier" placeholder="Tarkett" data-test="new-supplier" />
                </x-ui.field>
                <x-ui.field :label="__('Source of the files')" for="source_id">
                    <select id="source_id" class="ui-select" wire:model="source_id" data-test="source">
                        <option value="">{{ __('Unknown') }}</option>
                        @foreach ($this->sources as $source)
                            <option value="{{ $source->id }}">{{ $source->name }} · {{ $source->kind }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('Collection')" for="collection">
                    <input id="collection" class="ui-input" wire:model="collection" />
                </x-ui.field>
                <x-ui.field :label="__('Supplier product code')" for="supplier_product_code">
                    <input id="supplier_product_code" class="ui-input" wire:model="supplier_product_code" data-test="product-code" />
                </x-ui.field>
                <div class="ui-form ui-form--2">
                    <x-ui.field :label="__('Tile width (mm)')" for="tile_width_mm" :error="$errors->first('tile_width_mm')">
                        <input id="tile_width_mm" class="ui-input" type="number" step="0.01" wire:model="tile_width_mm" />
                    </x-ui.field>
                    <x-ui.field :label="__('Tile height (mm)')" for="tile_height_mm" :error="$errors->first('tile_height_mm')">
                        <input id="tile_height_mm" class="ui-input" type="number" step="0.01" wire:model="tile_height_mm" />
                    </x-ui.field>
                </div>
            </div>

            <x-ui.field :label="__('Description')" for="description">
                <textarea id="description" class="ui-textarea" wire:model="description" rows="3"></textarea>
            </x-ui.field>

            <div class="ui-form__section">
                <h3>{{ __('First variant') }}</h3>
                <p>{{ __('Leave empty for a single default variant. More variants can be added on the record.') }}</p>
                <div class="ui-form ui-form--2">
                    <x-ui.field :label="__('Colourway')" for="colourway">
                        <input id="colourway" class="ui-input" wire:model="colourway" placeholder="Ashen" data-test="colourway" />
                    </x-ui.field>
                    <x-ui.field :label="__('Supplier colour code')" for="colourway_code">
                        <input id="colourway_code" class="ui-input" wire:model="colourway_code" placeholder="634014001" data-test="colourway-code" />
                    </x-ui.field>
                    <x-ui.field :label="__('Finish')" for="finish">
                        <input id="finish" class="ui-input" wire:model="finish" placeholder="Honed" />
                    </x-ui.field>
                </div>
            </div>
            @endif

            <div class="ui-form__section">
                <h3>{{ __('Candidate maps') }}</h3>
                <p>{{ __('Drop a named texture set for automatic role assignment, or use the explicit fields. Filenames identify roles only; colour and normal interpretation stay explicit.') }}</p>
                @error('maps')<p class="ui-field__error">{{ $message }}</p>@enderror
                <x-ui.field :label="__('Quick assign a texture set')" for="auto-maps" :error="$errors->first('auto_maps')">
                    <input id="auto-maps" class="ui-input" type="file" wire:model="auto_maps" accept="image/*" multiple data-test="auto-maps" />
                </x-ui.field>
                <div class="ui-form ui-form--2">
                    @foreach ($this->roles as $role)
                        <x-ui.field :label="$role->name" for="map-{{ $role->slug }}" :error="$errors->first('maps.'.$role->slug)">
                            <input id="map-{{ $role->slug }}" class="ui-input" type="file" wire:model="maps.{{ $role->slug }}" accept="image/*" data-test="map-{{ $role->slug }}" />
                            @if ($maps[$role->slug] ?? null)
                                <small class="ui-file-picked">✓ {{ $maps[$role->slug]->getClientOriginalName() }}</small>
                            @endif
                        </x-ui.field>
                    @endforeach
                    <x-ui.field :label="__('Normal map convention')" for="normal_convention" :error="$errors->first('normal_convention')">
                        <select id="normal_convention" class="ui-select" wire:model="normal_convention" data-test="normal-convention">
                            <option value="opengl">{{ __('OpenGL (+Y)') }}</option>
                            <option value="directx">{{ __('DirectX (−Y)') }}</option>
                        </select>
                    </x-ui.field>
                </div>
            </div>

            <div class="ui-notice">
                <span class="ui-notice__rule"></span>
                <div><strong>{{ __('Review-safe by default') }}</strong><p>{{ __('The new set is stored beside the current one as a candidate. A reviewer must approve it before a version can publish it to Revit, Omniverse or the virtual drive.') }}</p></div>
            </div>

            <div class="ui-actions">
                <x-ui.button type="submit" data-test="save">{{ $mode === 'new' ? __('Create and import') : __('Upload improvement') }}</x-ui.button>
                <x-ui.button :href="route('materials.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</section>
