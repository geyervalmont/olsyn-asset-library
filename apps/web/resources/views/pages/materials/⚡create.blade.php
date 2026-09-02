<?php

use App\Actions\Materials\CreateMaterial;
use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Library\FileStore;
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
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Add material')] class extends Component {
    use WithFileUploads;

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

    /** @var array<string, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null> */
    public array $maps = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
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

    public function save(CreateMaterial $createMaterial, FileStore $files, CreateRepresentation $createRepresentation, RecordProvenance $provenance): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'category_id' => ['required', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'new_supplier' => ['nullable', 'string', 'max:120', 'required_without_all:supplier_id'],
            'source_id' => ['nullable', 'exists:sources,id'],
            'collection' => ['nullable', 'string', 'max:120'],
            'supplier_product_code' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tile_width_mm' => ['nullable', 'numeric', 'min:0'],
            'tile_height_mm' => ['nullable', 'numeric', 'min:0'],
            'colourway' => ['nullable', 'string', 'max:120'],
            'colourway_code' => ['nullable', 'string', 'max:120'],
            'finish' => ['nullable', 'string', 'max:120'],
            'maps' => ['array'],
            'maps.*' => ['nullable', 'image', 'max:102400'],
        ]);

        $user = auth()->user();
        $source = $validated['source_id'] !== '' ? Source::query()->find($validated['source_id']) : null;

        $material = DB::transaction(function () use ($validated, $user, $files, $createMaterial, $createRepresentation, $provenance, $source): Material {
            $supplierId = $validated['supplier_id'] !== '' ? (int) $validated['supplier_id'] : null;

            if ($supplierId === null && trim($validated['new_supplier'] ?? '') !== '') {
                $supplierId = (int) Supplier::query()->firstOrCreate(['name' => trim($validated['new_supplier'])])->getKey();
            }

            $attributes = array_filter([
                'colourway' => $validated['colourway'] !== '' ? ['value' => $validated['colourway'], 'supplier_code' => $validated['colourway_code'] ?: null] : null,
                'finish' => $validated['finish'] !== '' ? $validated['finish'] : null,
            ]);

            $material = $createMaterial->handle([
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

            $variant = $material->variants->first();
            $stored = [];

            foreach ($this->maps as $role => $upload) {
                if ($upload !== null) {
                    $stored[$role] = $files->store($upload, $upload->getClientOriginalName());
                }
            }

            if ($stored !== [] && $variant !== null) {
                $reference = $stored['base_color'] ?? reset($stored);
                $pixels = max((int) ($reference->width_px ?? 0), (int) ($reference->height_px ?? 0)) ?: 1024;

                $createRepresentation->handle($variant, 'pbr', $pixels, $stored, createdBy: $user);

                $provenance->handle(
                    $variant,
                    'uploaded',
                    $user,
                    outputs: collect($stored)->map(fn ($file, $role) => [$file, $role])->values()->all(),
                    source: $source,
                    notes: 'Uploaded through the library.',
                );
            }

            return $material;
        });

        $this->redirect(route('materials.show', $material), navigate: true);
    }
}; ?>

<section style="max-width: 880px">
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Library') }}</x-ui.eyebrow>
            <h1>{{ __('Add material') }}</h1>
            <p class="ui-page-head__lede">{{ __('One record per supplier product. Upload the canonical PBR maps; Revit and Omniverse sets are derived from them.') }}</p>
        </div>
    </div>

    <x-ui.panel>
        <form wire:submit="save" class="ui-form">
            <div class="ui-form ui-form--2">
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

            <div class="ui-form__section">
                <h3>{{ __('Canonical maps') }}</h3>
                <p>{{ __('PNG or JPG. Normal maps in OpenGL convention, roughness (not glossiness).') }}</p>
                <div class="ui-form ui-form--2">
                    @foreach ($this->roles as $role)
                        <x-ui.field :label="$role->name" for="map-{{ $role->slug }}" :error="$errors->first('maps.'.$role->slug)">
                            <input id="map-{{ $role->slug }}" class="ui-input" type="file" wire:model="maps.{{ $role->slug }}" accept="image/*" data-test="map-{{ $role->slug }}" />
                        </x-ui.field>
                    @endforeach
                </div>
            </div>

            <div class="ui-actions">
                <x-ui.button type="submit" data-test="save">{{ __('Create material') }}</x-ui.button>
                <x-ui.button :href="route('materials.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</section>
