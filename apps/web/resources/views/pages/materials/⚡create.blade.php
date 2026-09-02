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

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Add material') }}</flux:heading>
    <flux:text class="mt-1">{{ __('One record per supplier product. Upload the canonical PBR maps; Revit and Omniverse sets are derived from them.') }}</flux:text>

    <form wire:submit="save" class="mt-8 space-y-8">
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="name" :label="__('Name')" placeholder="Academix" required data-test="name" />
            <flux:select wire:model="category_id" :label="__('Category')" :placeholder="__('Choose…')" data-test="category">
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }} ({{ $category->code }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="supplier_id" :label="__('Supplier')" :placeholder="__('In-house or new supplier')" data-test="supplier">
                <flux:select.option value="">{{ __('In-house (OPAL)') }}</flux:select.option>
                @foreach ($this->suppliers as $supplier)
                    <flux:select.option value="{{ $supplier->id }}">{{ $supplier->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="new_supplier" :label="__('Or a new supplier')" placeholder="Tarkett" data-test="new-supplier" />
            <flux:select wire:model="source_id" :label="__('Source of the files')" :placeholder="__('Unknown')" data-test="source">
                <flux:select.option value="">{{ __('Unknown') }}</flux:select.option>
                @foreach ($this->sources as $source)
                    <flux:select.option value="{{ $source->id }}">{{ $source->name }} · {{ $source->kind }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="collection" :label="__('Collection')" />
            <flux:input wire:model="supplier_product_code" :label="__('Supplier product code')" data-test="product-code" />
            <flux:input wire:model="tile_width_mm" :label="__('Tile width (mm)')" type="number" step="0.01" />
            <flux:input wire:model="tile_height_mm" :label="__('Tile height (mm)')" type="number" step="0.01" />
        </div>

        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        <div>
            <flux:heading size="lg">{{ __('First variant') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Leave empty for a single default variant. More variants can be added on the record.') }}</flux:text>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <flux:input wire:model="colourway" :label="__('Colourway')" placeholder="Ashen" data-test="colourway" />
                <flux:input wire:model="colourway_code" :label="__('Supplier colour code')" placeholder="634014001" data-test="colourway-code" />
                <flux:input wire:model="finish" :label="__('Finish')" placeholder="Honed" />
            </div>
        </div>

        <div>
            <flux:heading size="lg">{{ __('Canonical maps') }}</flux:heading>
            <flux:text class="mt-1">{{ __('PNG or JPG. Normal maps in OpenGL convention, roughness (not glossiness).') }}</flux:text>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach ($this->roles as $role)
                    <flux:input type="file" wire:model="maps.{{ $role->slug }}" :label="$role->name" accept="image/*" data-test="map-{{ $role->slug }}" />
                @endforeach
            </div>
            <flux:error name="maps.*" />
        </div>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary" data-test="save">{{ __('Create material') }}</flux:button>
            <flux:button :href="route('materials.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
