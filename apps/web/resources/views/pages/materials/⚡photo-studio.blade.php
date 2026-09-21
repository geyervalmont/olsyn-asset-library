<?php

use App\Actions\Clients\IssueClientCommand;
use App\Actions\Materials\PromoteStudioRevision;
use App\Enums\CommandType;
use App\Library\Procedural\{ProceduralAsset, ProceduralBake, StudioPreviewStore};
use App\Library\Studio\DraftStore;
use App\Models\{Category, ClientSession, Material, Representation, StudioDraft, StudioRevision, SynthesisRun, Tenant, User};
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\{Computed, Locked, Title, Url};
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Photo Material Studio')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $draftState = 'active';
    #[Locked] public ?int $compareId = null;
    public int $brightness = 100;
    public int $saturation = 100;
    public int $photo_blend = 0;

    #[Url] public string $draft = '';
    #[Locked] public ?int $revisionId = null;
    public string $name = 'Untitled material';
    public $width_mm = '';
    public $height_mm = '';
    public int $resolution = 1024;
    public int $cleanup = 0;
    public array $crop = ['x' => 50, 'y' => 50, 'size' => 100];
    public string $tint = '#ffffff';
    public int $tint_amount = 0;
    public string $roughness = '';
    public string $metallic = '';
    public string $destination = 'new';
    public string $category_id = '';
    public string $material_id = '';
    public string $variant_id = '';
    public string $colourway = '';
    public string $importRepresentation = '';

    public function mount(): void
    {
        $this->authorizeEditor();
        if ($this->draft !== '') {
            $this->open($this->draft);
        }
    }

    private function authorizeEditor(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
    }

    #[Computed] public function current(): ?StudioDraft
    {
        return $this->draft === '' ? null : StudioDraft::query()->owned()->where('uuid', $this->draft)->firstOrFail();
    }

    #[Computed] public function revision(): ?StudioRevision
    {
        return $this->current?->revisions()->findOrFail($this->revisionId);
    }

    #[Computed] public function drafts()
    {
        return StudioDraft::query()->owned()->with('head.run')->withCount('revisions')
            ->where('state', in_array($this->draftState, ['active', 'promoted'], true) ? $this->draftState : 'active')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$this->search.'%')->orWhere('uuid', 'like', '%'.$this->search.'%')))
            ->latest('updated_at')->paginate(12);
    }

    #[Computed] public function activity(): ?SynthesisRun
    {
        if ($this->current === null) { return null; }
        return SynthesisRun::query()->whereHas('revision', fn ($q) => $q->where('studio_draft_id', $this->current->id))
            ->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->oldest()->first() ?? $this->revision?->run
            ?? SynthesisRun::query()->whereHas('revision', fn ($q) => $q->where('studio_draft_id', $this->current->id))->latest('id')->first();
    }

    #[Computed] public function comparison(): ?StudioRevision
    {
        return $this->compareId === null ? null : $this->current?->revisions()->findOrFail($this->compareId);
    }

    public function compare(int $id): void
    {
        $this->authorizeEditor();
        $this->current->revisions()->findOrFail($id);
        $this->compareId = $id;
        unset($this->comparison);
    }

    #[Computed] public function materials()
    {
        return Material::query()->visibleTo(auth()->user())->orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed] public function importSets()
    {
        return Representation::query()->where('kind', Representation::KIND_PBR_SET)
            ->whereHas('variant.material', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['variant.material', 'quality'])->latest()->limit(200)->get();
    }

    #[Computed] public function variants()
    {
        return $this->material_id === '' ? collect() : Material::query()->visibleTo(auth()->user())->findOrFail($this->material_id)->variants()->get();
    }

    #[Computed] public function previewSet(): array
    {
        $revision = $this->revision;
        if ($revision === null || ! isset($revision->artifacts['base_color'])) {
            return [];
        }
        $set = ['key' => 'photo-'.$revision->id, 'tile_mm' => (float) ($revision->document['width_mm'] ?? 1000), 'tile_height_mm' => (float) ($revision->document['height_mm'] ?? 1000), 'displacement_scale' => 0.01, 'normal_convention' => $revision->document['normal_convention'] ?? 'opengl'];
        foreach (DraftStore::MAPS as $role) {
            if (isset($revision->artifacts[$role])) {
                $set[$role] = route('studio-artifacts.show', ['revision' => $revision->id, 'role' => $role]);
            }
        }
        return $set;
    }

    public function open(string $uuid): void
    {
        $this->authorizeEditor();
        $draft = StudioDraft::query()->owned()->where('uuid', $uuid)->firstOrFail();
        $this->draft = $draft->uuid;
        $this->revisionId = $draft->head_id;
        $this->name = $draft->name;
        unset($this->current, $this->revision, $this->previewSet, $this->activity, $this->comparison);
        $doc = $this->revision->document;
        $this->width_mm = $doc['width_mm'] ?? '';
        $this->height_mm = $doc['height_mm'] ?? '';
        $this->resolution = $doc['resolution'] ?? 1024;
        $this->cleanup = $doc['cleanup'] ?? 0;
        $this->crop = $doc['crop'] ?? ['x' => 50, 'y' => 50, 'size' => 100];
        $this->compareId = null;
        $this->brightness = 100;
        $this->saturation = 100;
        $this->photo_blend = 0;
        $this->tint_amount = 0;
        $this->roughness = '';
        $this->metallic = '';
        $this->resetValidation();
    }

    public function saveDraft(): void
    {
        $this->authorizeEditor();
        $this->validate(['name' => 'required|string|max:120', 'width_mm' => 'nullable|numeric|gt:0|max:100000', 'height_mm' => 'nullable|numeric|gt:0|max:100000', 'resolution' => 'required|in:512,1024', 'cleanup' => 'required|integer|min:0|max:100', 'crop.x' => 'required|numeric|min:0|max:100', 'crop.y' => 'required|numeric|min:0|max:100', 'crop.size' => 'required|numeric|min:10|max:100']);
        $revision = $this->revision;
        abort_unless($revision !== null, 404);
        $doc = array_replace($revision->document, ['width_mm' => $this->width_mm === '' ? null : (float) $this->width_mm, 'height_mm' => $this->height_mm === '' ? null : (float) $this->height_mm, 'resolution' => $this->resolution, 'cleanup' => $this->cleanup, 'crop' => $this->crop]);
        $this->current->update(['name' => $this->name]);
        if ($doc != $revision->document) {
            $changedSource = ($doc['crop'] != ($revision->document['crop'] ?? [])) || $doc['cleanup'] != ($revision->document['cleanup'] ?? 0) || $doc['resolution'] != ($revision->document['resolution'] ?? 1024);
            if ($changedSource) { unset($doc['edits']); }
            $next = app(DraftStore::class)->revise($this->current, $revision->id, $doc, $changedSource ? [] : $revision->artifacts);
            $this->revisionId = $next->id;
        }
        unset($this->current, $this->revision, $this->previewSet, $this->drafts, $this->activity);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'draftState'], true)) {
            $this->resetPage();
        }
        if ($this->draft !== '' && $this->current?->state === 'active' && (in_array($property, ['name', 'width_mm', 'height_mm', 'cleanup', 'resolution'], true) || str_starts_with($property, 'crop.'))) {
            $this->saveDraft();
        }
    }

    public function generate(): void
    {
        if ($this->activity && ! $this->activity->terminal()) { return; }
        $this->saveDraft();
        $source = $this->revision;
        if ($source->run !== null && ! $source->run->terminal()) { return; }
        $revision = DB::transaction(function () use ($source) {
            $revision = $source;
            if ($source->run !== null || ! empty($source->artifacts)) {
                $document = $source->document;
                unset($document['edits']);
                $revision = app(DraftStore::class)->revise($this->current, $source->id, $document);
            }
            app(DraftStore::class)->generate($revision);
            return $revision;
        });
        $this->revisionId = $revision->id;
        unset($this->current, $this->revision, $this->previewSet, $this->activity, $this->comparison);
    }

    public function poll(): void
    {
        unset($this->revision, $this->previewSet, $this->activity, $this->drafts);
    }

    public function cancel(): void
    {
        $this->authorizeEditor();
        $run = $this->activity;
        if ($run !== null) {
            DB::transaction(function () use ($run): void {
                $run = $run->newQuery()->lockForUpdate()->findOrFail($run->id);
                if (! $run->terminal()) {
                    $run->update(['status' => 'cancelled', 'stage' => 'cancelled']);
                }
            });
        }
        unset($this->revision, $this->activity, $this->drafts);
    }

    public function discard(): void
    {
        $this->authorizeEditor();
        $this->current?->update(['state' => 'discarded']);
        $this->draft = '';
        $this->revisionId = null;
        unset($this->current, $this->revision, $this->drafts, $this->activity, $this->comparison);
    }

    public function fork(): void
    {
        $this->authorizeEditor();
        $draft = app(DraftStore::class)->fork($this->revision);
        $this->open($draft->uuid);
        unset($this->drafts);
    }

    public function restore(int $id): void
    {
        $this->authorizeEditor();
        $source = $this->current->revisions()->findOrFail($id);
        $next = app(DraftStore::class)->revise($this->current, $this->current->head_id, $source->document, $source->artifacts);
        $this->open($this->draft);
    }

    public function adjust(): void
    {
        $this->authorizeEditor();
        $settings = $this->validate(['tint' => 'required|regex:/^#[a-fA-F0-9]{6}$/', 'tint_amount' => 'required|integer|min:0|max:100', 'roughness' => 'nullable|numeric|min:0|max:1', 'metallic' => 'nullable|numeric|min:0|max:1', 'brightness' => 'required|integer|min:50|max:150', 'saturation' => 'required|integer|min:0|max:200', 'photo_blend' => 'required|integer|min:0|max:100']);
        $revision = $this->revision;
        $maps = app(DraftStore::class)->adjust($revision, $settings);
        $doc = $revision->document;
        $doc['edits'][] = $settings;
        app(DraftStore::class)->revise($this->current, $revision->id, $doc, $maps);
        $this->open($this->draft);
    }

    public function import(): void
    {
        $this->authorizeEditor();
        $this->validate(['importRepresentation' => 'required|integer']);
        $rep = Representation::query()->findOrFail($this->importRepresentation);
        abort_unless($rep->variant->material->isVisibleTo(auth()->user()), 404);
        $maps = [];
        foreach ($rep->filesByRole() as $role => $file) {
            if (in_array($role, DraftStore::MAPS, true)) {
                $image = new Imagick;
                $image->readImageBlob($file->contents());
                if ($role === 'normal' && ($rep->metadata['normal_convention'] ?? 'opengl') === 'directx') {
                    $image->negateImage(false, Imagick::CHANNEL_GREEN);
                }
                $image->setImageFormat('png');
                $image->stripImage();
                $maps[$role] = app(DraftStore::class)->put($image->getImageBlob(), $role);
                $image->clear();
            }
        }
        if (! isset($maps['base_color'])) {
            $this->addError('importRepresentation', 'Choose a map set with a base colour image.');
            return;
        }
        $draft = DB::transaction(function () use ($rep, $maps) {
            $draft = StudioDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'tenant_id' => Tenant::current()->id, 'name' => $rep->variant->material->name.' — study', 'source_representation_id' => $rep->id]);
            $rev = $draft->revisions()->create(['document' => ['schema' => 1, 'source' => $maps['base_color'], 'normal_convention' => 'opengl', 'width_mm' => $rep->metadata['tile_width_mm'] ?? $rep->variant->effectiveTileWidthMm(), 'height_mm' => $rep->metadata['tile_height_mm'] ?? $rep->variant->effectiveTileHeightMm(), 'resolution' => 1024, 'cleanup' => 0, 'crop' => ['x' => 50, 'y' => 50, 'size' => 100]], 'artifacts' => $maps]);
            $draft->update(['head_id' => $rev->id]);
            return $draft;
        });
        $this->open($draft->uuid);
        unset($this->drafts);
    }

    public function promote(): void
    {
        $this->saveDraft();
        $rep = app(PromoteStudioRevision::class)->handle($this->revision, ['mode' => $this->destination, 'name' => $this->name, 'category_id' => $this->category_id ?: null, 'material_id' => $this->material_id ?: null, 'variant_id' => $this->variant_id ?: null, 'colourway' => $this->colourway], auth()->user());
        Flux::toast(variant: 'success', text: 'Saved as a candidate for review.');
        $this->redirect(route('materials.show', $rep->variant->material), navigate: true);
    }

    public function apply(): void
    {
        $this->saveDraft();
        $this->validate(['width_mm' => 'required|numeric|gt:0', 'height_mm' => 'required|numeric|gt:0']);
        if ((float) $this->width_mm !== (float) $this->height_mm) {
            $this->addError('apply', 'Direct Revit apply currently supports square samples. Save a library candidate to preserve both dimensions.');
            return;
        }
        $session = ClientSession::query()->where('user_id', auth()->id())->where('platform', 'revit')->live()->latest('last_seen_at')->first();
        if ($session === null) {
            $this->addError('apply', 'Connect a live Revit session with this account first.');
            return;
        }
        $revision = $this->revision;
        abort_unless(isset($revision->artifacts['base_color']), 422);
        $assets = [];
        foreach (DraftStore::MAPS as $role) {
            if (isset($revision->artifacts[$role])) {
                $bytes = app(DraftStore::class)->bytes($revision->artifacts[$role]);
                $assets[] = new ProceduralAsset($role, $role.'.png', 'png', 'image/png', hash('sha256', $bytes), $bytes);
            }
        }
        $bake = new ProceduralBake('photo', '1', hash('sha256', json_encode($revision->document)), $revision->artifacts['base_color']['width'], $revision->artifacts['base_color']['height'], (float) $this->width_mm, (float) $this->height_mm, $assets);
        $studio = app(StudioPreviewStore::class)->put(auth()->user(), $bake, $this->name);
        app(IssueClientCommand::class)->handle($session, auth()->user(), CommandType::Apply, ['studio' => $studio]);
        Flux::toast(variant: 'success', text: 'Sent this draft to Revit.');
    }
}; ?>

<section class="mx-auto max-w-7xl space-y-6" data-test="photo-studio">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><x-ui.eyebrow>Material Studio</x-ui.eyebrow><h1 class="text-3xl font-semibold">From photograph to finish</h1><p class="mt-2 text-zinc-500">Capture a surface, refine its material, and keep your experiments as private drafts.</p></div>
        <x-ui.button :href="route('materials.studio')" variant="quiet" wire:navigate>Procedural Studio</x-ui.button>
    </div>
    @if($errors->any())<div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-900">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="grid gap-6 lg:grid-cols-[300px_1fr]">
        <aside class="self-start rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700" x-data="{ expanded: @js($draft === '') }">
            <button type="button" class="w-full text-left text-sm font-semibold lg:hidden" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded">Browse drafts / start a material <span class="float-right" x-text="expanded ? '−' : '+'"></span></button>
            <div x-show="expanded" class="mt-4 space-y-5 lg:mt-0 lg:block!">
            <div class="flex items-center justify-between"><h2 class="font-semibold">Your drafts</h2><span class="text-xs text-zinc-500">{{ $this->drafts->total() }} {{ $this->drafts->total() === 1 ? 'draft' : 'drafts' }}</span></div>
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search name or draft ID…" aria-label="Search drafts" />
            <flux:select wire:model.live="draftState" aria-label="Draft collection"><option value="active">In progress</option><option value="promoted">Saved to library</option></flux:select>
            <div class="space-y-2">
                @forelse($this->drafts as $item)
                    <button wire:key="draft-{{ $item->id }}" wire:click="open('{{ $item->uuid }}')" aria-current="{{ $draft === $item->uuid ? 'true' : 'false' }}" class="flex w-full gap-3 rounded-xl border p-2 text-left {{ $draft === $item->uuid ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800' : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                        @if($item->head)<img loading="lazy" alt="{{ $item->name }} preview" src="{{ route('studio-artifacts.show', ['revision' => $item->head_id, 'role' => $item->head->previewRole(), 'thumbnail' => 1]) }}" class="h-16 w-16 shrink-0 rounded-lg object-cover" />@endif
                        <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ $item->name }}</span><span class="block text-xs text-zinc-500">{{ substr($item->uuid, 0, 5) }} · {{ $item->head?->label() }}</span><span class="mt-1 block text-xs text-zinc-500">{{ $item->updated_at->diffForHumans() }} · {{ $item->revisions_count }} revisions</span></span>
                    </button>
                @empty
                    <p class="text-sm text-zinc-500">No drafts found. Start with a photo below.</p>
                @endforelse
            </div>
            {{ $this->drafts->links() }}
            <form method="POST" action="{{ route('materials.studio.photos.upload') }}" enctype="multipart/form-data" class="space-y-3 border-t border-zinc-200 pt-4" x-data="{ uploading: false }" x-on:submit="uploading = true">
                @csrf
                <flux:input name="name" label="Draft name" value="Untitled material" required maxlength="120" />
                <label class="block text-sm font-medium">Take or upload a photo<input class="mt-2 block w-full text-sm" type="file" accept="image/jpeg,image/png,image/webp" name="photo" required /></label>
                <p class="text-xs text-zinc-500">JPEG, PNG or WebP · up to 20 MB. Use diffuse light and fill the frame with the surface.</p>
                <flux:button type="submit" variant="primary" x-bind:disabled="uploading">Create photo draft</flux:button>
                <span x-show="uploading" x-cloak class="text-sm">Saving photo…</span>
            </form>
            <form wire:submit="import" class="space-y-3 border-t border-zinc-200 pt-4">
                <flux:select wire:model="importRepresentation" label="Start from a library material"><option value="">Choose a material…</option>@foreach($this->importSets as $set)<option value="{{ $set->id }}">{{ $set->variant->material->name }} · {{ $set->variant->name }} · {{ $set->quality->name }}</option>@endforeach</flux:select>
                <flux:button type="submit">Repair / derive material</flux:button>
            </form>
            </div>
        </aside>
        <main class="min-w-0 space-y-5">
            @if($this->revision)
                @php($revision = $this->revision)
                @php($run = $this->activity)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-xl font-semibold">{{ $this->current->name }}</h2><p class="text-sm text-zinc-500">Revision {{ $revision->id }} · {{ ucfirst($this->current->state) }} <span wire:loading wire:target="saveDraft,adjust,name,width_mm,height_mm,cleanup,resolution,crop.x,crop.y,crop.size">· Saving…</span></p></div>
                    <div class="flex gap-2"><flux:button wire:click="fork">Derive colourway</flux:button><flux:button wire:click="discard" wire:confirm="Discard this draft? Its library outputs will remain available.">Discard</flux:button></div>
                </div>
                <ol class="grid grid-cols-3 gap-2 text-sm" aria-label="Material workflow">
                    <li class="rounded-lg bg-zinc-100 p-3 dark:bg-zinc-800">1. Prepare photo</li>
                    <li class="rounded-lg bg-zinc-100 p-3 dark:bg-zinc-800">2. Generate & refine</li>
                    <li class="rounded-lg bg-zinc-100 p-3 dark:bg-zinc-800">3. Use material</li>
                </ol>
                @if($run)
                    <x-ui.synthesis-progress :run="$run" :current-revision="$revision->id" />
                @endif
                @if($this->previewSet)
                    <div class="ui-viewer relative min-h-[420px] overflow-hidden rounded-2xl bg-zinc-100" wire:key="photo-viewer-{{ $revision->id }}-{{ count($revision->artifacts ?? []) }}" x-data="materialViewer(@js(['sets' => ['preview' => $this->previewSet], 'autoRotate' => false, 'objectSizeMm' => (float) ($width_mm ?: 1000), 'shape' => 'panel', 'shapeKey' => 'photo-studio']))" x-init="show('preview')">
                        <div class="absolute left-3 top-3 z-10 flex gap-2"><button class="rounded bg-white px-3 py-2 text-black" x-on:click="shape = 'panel'; inspectSurface()">Sample</button><button class="rounded bg-white px-3 py-2 text-black" x-on:click="shape = 'ball'; inspectSurface()">Sphere</button><button class="rounded bg-white px-3 py-2 text-black" x-on:click="toggleRotation()">Rotate</button></div>
                        <div x-ref="stage" wire:ignore class="ui-viewer__stage h-[420px] w-full"></div>
                    </div>
                    @if(isset($revision->artifacts['prepared']))
                        <details class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <summary class="cursor-pointer text-sm font-semibold">Compare photo and base colour · {{ $revision->artifacts['base_color']['width'] }} px</summary>
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                @foreach(['prepared' => 'Aligned source photo', 'base_color' => 'Current base colour'] as $role => $label)
                                    <figure><a target="_blank" href="{{ route('studio-artifacts.show', ['revision' => $revision->id, 'role' => $role]) }}"><img alt="{{ $label }}" src="{{ route('studio-artifacts.show', ['revision' => $revision->id, 'role' => $role]) }}" class="aspect-square w-full rounded-lg object-contain" /></a><figcaption class="mt-2 text-sm text-zinc-500">{{ $label }} · click for full size</figcaption></figure>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">@foreach(DraftStore::MAPS as $role)@if(isset($revision->artifacts[$role]))<a href="{{ route('studio-artifacts.show', ['revision'=>$revision->id,'role'=>$role]) }}" target="_blank" class="overflow-hidden rounded-lg border border-zinc-200"><img alt="{{ str_replace('_',' ', $role) }} map" src="{{ route('studio-artifacts.show', ['revision'=>$revision->id,'role'=>$role]) }}" class="aspect-square w-full object-cover" /><span class="block p-2 text-xs">{{ ucfirst(str_replace('_',' ', $role)) }}</span></a>@endif @endforeach</div>
                @endif
                <details @if(!$this->previewSet) open @endif class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <summary class="cursor-pointer font-semibold">1. Source and preparation</summary>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="relative overflow-hidden rounded-lg bg-zinc-100" style="aspect-ratio: {{ $revision->document['source']['width'] }} / {{ $revision->document['source']['height'] }}">
                                <img alt="Original surface, before synthesis" src="{{ route('studio-artifacts.show', ['revision'=>$revision->id,'role'=>'source']) }}" class="block w-full" />
                                @php($sample = min($revision->document['source']['width'], $revision->document['source']['height']) * $crop['size'] / 100)
                                <div class="pointer-events-none absolute border-2 border-white" style="box-shadow: 0 0 0 100vmax rgb(0 0 0 / 45%); width: {{ 100 * $sample / $revision->document['source']['width'] }}%; height: {{ 100 * $sample / $revision->document['source']['height'] }}%; left: {{ (1 - $sample / $revision->document['source']['width']) * $crop['x'] }}%; top: {{ (1 - $sample / $revision->document['source']['height']) * $crop['y'] }}%;"></div>
                            </div>
                            <p class="mt-2 text-xs text-zinc-500">Highlighted area will become the material sample.</p>
                        </div>
                        <div class="space-y-3">
                            <p class="text-sm text-zinc-500">Select a square sample. Position controls move the crop within the photo; 100% uses the largest square.</p>
                            @foreach(['x'=>'Horizontal position','y'=>'Vertical position','size'=>'Crop size'] as $key=>$label)<label class="block text-sm">{{ $label }} · {{ $crop[$key] }}%<input class="block w-full" type="range" min="{{ $key === 'size' ? 10 : 0 }}" max="100" wire:model.live.debounce.500ms="crop.{{ $key }}" /></label>@endforeach
                            <label class="block text-sm">Glare cleanup · {{ $cleanup }}%<input class="block w-full" type="range" min="0" max="100" wire:model.live.debounce.500ms="cleanup" @disabled(!config('synthesis.cleanup_enabled')) /></label>
                            <p class="text-xs text-zinc-500">Use a close, front-on photo of one flat surface. Optional cleanup can change surface detail. Regeneration starts from the source; earlier finish edits remain in history.</p>
                            <flux:select wire:model.live="resolution" label="Generation size"><option value="512">512 px · quick study</option><option value="1024">1024 px · larger maps</option></flux:select>
                            @php($cropPixels = (int) round(min($revision->document['source']['width'], $revision->document['source']['height']) * $crop['size'] / 100))
                            <p class="text-xs text-zinc-500">{{ $cropPixels }} × {{ $cropPixels }} px source crop → {{ $resolution }} × {{ $resolution }} px maps. Generation estimates surface properties; it does not upscale or recover lost detail.</p>
                            @if($cropPixels < $resolution)<p class="text-sm text-amber-700 dark:text-amber-300">This crop is smaller than the output. Use a wider crop or a sharper source to retain more detail.</p>@endif
                            <flux:button wire:click="generate" variant="primary" :disabled="!config('synthesis.enabled') || ($run && !$run->terminal()) || $this->current->state !== 'active'">{{ $this->previewSet ? 'Generate a new version' : 'Generate material' }}</flux:button>
                            @unless(config('synthesis.enabled'))<p class="text-sm text-zinc-500">Photo generation is not enabled yet. You can keep this draft or edit a library material.</p>@endunless
                        </div>
                    </div>
                    @foreach(['prepared','cleaned'] as $role)@if(isset($revision->artifacts[$role]))<figure class="mt-4 inline-block w-40"><img alt="{{ $role }} input" src="{{ route('studio-artifacts.show', ['revision'=>$revision->id,'role'=>$role]) }}" /><figcaption class="text-sm">{{ ucfirst($role) }}</figcaption></figure>@endif @endforeach
                </details>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-3 rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <h3 class="font-semibold">2. Refine this version</h3>
                        <flux:input wire:model.live.debounce.700ms="name" label="Material name" />
                        <div class="grid grid-cols-2 gap-3"><flux:input wire:model.live.debounce.700ms="width_mm" label="Width (mm)" type="number" min="1" placeholder="Unknown" /><flux:input wire:model.live.debounce.700ms="height_mm" label="Height (mm)" type="number" min="1" placeholder="Unknown" /></div>
                        <p class="text-xs text-zinc-500">Set the physical size of the selected sample before applying or saving to the library.</p>
                        @if($this->previewSet)
                            <p class="text-sm text-zinc-500">Adjust colour and finish, then save a version to see the result. No GPU generation needed. Compare or restore any earlier version below.</p>
                            @foreach(['brightness' => ['Brightness', 50, 150], 'saturation' => ['Saturation', 0, 200]] as $control => [$label, $min, $max])
                                <label class="block text-sm" x-data="{ amount: $wire.entangle('{{ $control }}') }">{{ $label }} · <span x-text="amount"></span>%<input class="block w-full" type="range" min="{{ $min }}" max="{{ $max }}" x-model="amount" /></label>
                            @endforeach
                            @if(isset($revision->artifacts['prepared']))
                                <label class="block text-sm" x-data="{ amount: $wire.entangle('photo_blend') }">Original photo blend · <span x-text="amount"></span>%<input class="block w-full" type="range" min="0" max="100" x-model="amount" /></label>
                                <p class="text-xs text-zinc-500">Blend the aligned photo back into base colour to retain its appearance. This also brings back photographed shadows and highlights.</p>
                            @endif
                            <div class="flex items-center gap-3"><input type="color" wire:model="tint" aria-label="Colourway tint" /><label class="text-sm">Tint strength<input type="range" min="0" max="100" wire:model="tint_amount" /></label></div>
                            <flux:input wire:model="roughness" label="Roughness override (0–1)" type="number" min="0" max="1" step="0.05" placeholder="Keep generated map" />
                            <flux:input wire:model="metallic" label="Metalness override (0–1)" type="number" min="0" max="1" step="0.05" placeholder="Keep generated map" />
                            <p class="text-xs text-zinc-500">Use 0 for paint, stone, timber and fabric; 1 for bare metal.</p>
                            <flux:button wire:click="adjust" :disabled="$this->current->state !== 'active'">Save finish revision</flux:button>
                            <p class="text-xs text-zinc-500">Colour edits preserve normal and height maps. Roughness and metalness overrides replace those maps with a uniform value.</p>
                        @endif
                    </div>
                    <div class="space-y-3 rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <h3 class="font-semibold">3. Use this material</h3>
                        <flux:button wire:click="apply" :disabled="!$this->previewSet">Apply to Revit</flux:button>
                        <flux:select wire:model.live="destination" label="Library destination"><option value="new">New material</option><option value="colourway">New colourway</option><option value="improve">Improve existing variant</option></flux:select>
                        @if($destination === 'new')<flux:select wire:model="category_id" label="Category"><option value="">Choose category</option>@foreach(Category::query()->orderBy('name')->get() as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</flux:select>
                        @else<flux:select wire:model.live="material_id" label="Material"><option value="">Choose material</option>@foreach($this->materials as $material)<option value="{{ $material->id }}">{{ $material->code }} · {{ $material->name }}</option>@endforeach</flux:select>
                            @if($destination === 'colourway')<flux:input wire:model="colourway" label="Colourway name" />@else<flux:select wire:model="variant_id" label="Variant"><option value="">Choose variant</option>@foreach($this->variants as $variant)<option value="{{ $variant->id }}">{{ $variant->name }}</option>@endforeach</flux:select>@endif
                        @endif
                        <flux:button wire:click="promote" variant="primary" :disabled="!$this->previewSet || $this->current->state !== 'active'">Save candidate to library</flux:button>
                        <p class="text-xs text-zinc-500">@if($destination === 'improve')Approval replaces the variant’s earlier map sets and adopts this sample’s scale. Published versions remain available.@else Creates a reviewable candidate. Existing published materials are preserved.@endif</p>
                    </div>
                </div>
                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700" aria-label="Revision history">
                    <h3 class="font-semibold">Version history</h3>
                    <p class="mt-1 text-sm text-zinc-500">Compare a version before restoring it. Restoring makes a new version and keeps your history.</p>
                    @if($this->comparison)
                        @php($comparison = $this->comparison)
                        <div class="my-4 grid grid-cols-2 gap-3" data-test="revision-comparison">
                            @foreach(['Selected version' => $comparison, 'Current version' => $revision] as $label => $version)
                                <figure><a href="{{ route('studio-artifacts.show', ['revision' => $version->id, 'role' => $version->previewRole()]) }}" target="_blank"><img alt="{{ $label }} {{ $version->id }}" src="{{ route('studio-artifacts.show', ['revision' => $version->id, 'role' => $version->previewRole()]) }}" class="aspect-square w-full rounded-xl object-contain bg-zinc-100" /></a><figcaption class="mt-2 text-sm">{{ $label }} · #{{ $version->id }} · {{ $version->label() }}</figcaption></figure>
                            @endforeach
                        </div>
                        @if($this->current->state === 'active' && $comparison->id !== $revision->id)<flux:button wire:click="restore({{ $comparison->id }})">Restore selected version</flux:button>@endif
                    @endif
                    <div class="mt-4 max-h-96 space-y-2 overflow-y-auto">
                        @foreach($this->current->revisions()->with('run')->latest('id')->get() as $past)
                            <div wire:key="history-{{ $past->id }}" class="flex items-center gap-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                <img loading="lazy" alt="Version {{ $past->id }} preview" src="{{ route('studio-artifacts.show', ['revision' => $past->id, 'role' => $past->previewRole(), 'thumbnail' => 1]) }}" class="h-16 w-16 rounded-lg object-cover" />
                                <div class="min-w-0 flex-1"><p class="text-sm font-medium">{{ $past->label() }} @if($past->id === $revision->id)<span class="text-zinc-500">· Current</span>@endif</p><p class="text-xs text-zinc-500">#{{ $past->id }} · {{ $past->created_at->format('j M, g:i a') }} · {{ $past->document['resolution'] ?? '—' }} px · Crop {{ $past->document['crop']['size'] ?? 100 }}% · Cleanup {{ $past->document['cleanup'] ?? 0 }}%</p>
                                @if(!empty($past->document['edits']))<p class="text-xs text-zinc-500">{{ count($past->document['edits']) }} saved finish adjustments</p>@endif</div>
                                <flux:button size="sm" wire:click="compare({{ $past->id }})">Compare</flux:button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @else
                <div class="flex min-h-[500px] items-center justify-center rounded-2xl border border-dashed border-zinc-300 p-8 text-center"><div><h2 class="text-2xl font-semibold">Start with a real surface</h2><p class="mt-3 max-w-md text-zinc-500">Upload a photograph or reopen a draft. Generation creates editable material maps; your original capture stays private.</p></div></div>
            @endif
        </main>
    </div>
</section>
