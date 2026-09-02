<?php

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use App\Models\Target;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Drives')] class extends Component {
    public string $name = '';

    public string $root_path = '/materials';

    public string $target_id = '';

    public ?string $issuedToken = null;

    public ?string $issuedFor = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);
    }

    #[Computed]
    public function drives(): Collection
    {
        $namespace = app(DriveNamespace::class);

        return Drive::query()->with('target')->orderBy('name')->get()
            ->map(fn (Drive $drive) => ['drive' => $drive, 'entries' => count($namespace->entries($drive))]);
    }

    #[Computed]
    public function targets(): Collection
    {
        return Target::query()->orderBy('sort_order')->get();
    }

    public function issueToken(int $driveId): void
    {
        $drive = Drive::query()->findOrFail($driveId);

        $this->issuedToken = $drive->issueToken();
        $this->issuedFor = $drive->slug;
        unset($this->drives);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120', 'unique:drives,name'],
            'root_path' => ['required', 'string', 'max:120', 'regex:/^\/?[A-Za-z0-9_\-. ]+(\/[A-Za-z0-9_\-. ]+)*$/'],
            'target_id' => ['nullable', 'exists:targets,id'],
        ]);

        Drive::create([
            'name' => $validated['name'],
            'root_path' => $validated['root_path'],
            'target_id' => $validated['target_id'] !== '' ? (int) $validated['target_id'] : null,
        ]);

        $this->reset('name', 'target_id');
        $this->root_path = '/materials';
        unset($this->drives);
        Flux::toast(variant: 'success', text: __('Drive registered.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Drives') }}</flux:heading>
    <flux:text class="mt-1">{{ __('A drive is a namespace PrismFS projects: the current version of every material it can see, at the targets it serves.') }}</flux:text>

    @if ($issuedToken !== null)
        <flux:callout variant="warning" icon="key" class="mt-6" data-test="issued-token">
            <flux:callout.heading>{{ __('Token for :drive, shown once', ['drive' => $issuedFor]) }}</flux:callout.heading>
            <flux:callout.text>
                <code class="block break-all text-xs">{{ $issuedToken }}</code>
                <span class="mt-2 block text-xs">{{ __('Mount it with:') }}</span>
                <code class="block break-all text-xs">prismfs mount --manifest-url {{ route('prismfs.drives.manifest', $issuedFor) }} --manifest-token {{ $issuedToken }}</code>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="mt-6">
        @if ($this->drives->isEmpty())
            <flux:text data-test="no-drives">{{ __('No drives registered yet.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Drive') }}</flux:table.column>
                    <flux:table.column>{{ __('Root') }}</flux:table.column>
                    <flux:table.column>{{ __('Target') }}</flux:table.column>
                    <flux:table.column>{{ __('Files') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->drives as $row)
                        <flux:table.row :key="$row['drive']->id" data-test="drive-row">
                            <flux:table.cell variant="strong">{{ $row['drive']->name }} <code class="text-xs text-zinc-500">{{ $row['drive']->slug }}</code></flux:table.cell>
                            <flux:table.cell><code class="text-xs">{{ $row['drive']->root_path }}</code></flux:table.cell>
                            <flux:table.cell>{{ $row['drive']->target?->name ?? __('All targets') }}</flux:table.cell>
                            <flux:table.cell data-test="drive-entries">{{ $row['entries'] }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex gap-2">
                                    <flux:button :href="route('drives.manifest', $row['drive'])" size="xs" icon="arrow-down-tray">{{ __('Manifest') }}</flux:button>
                                    <flux:button wire:click="issueToken({{ $row['drive']->id }})" size="xs" icon="key" data-test="issue-token">
                                        {{ $row['drive']->hasToken() ? __('Rotate token') : __('Issue token') }}
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <form wire:submit="create" class="mt-8 flex flex-wrap items-end gap-3 rounded-xl border border-dashed border-neutral-300 p-5 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" placeholder="Studio Revit share" data-test="drive-name" />
        <flux:input wire:model="root_path" :label="__('Root path')" data-test="drive-root" />
        <flux:select wire:model="target_id" :label="__('Target')" :placeholder="__('All targets')" data-test="drive-target">
            <flux:select.option value="">{{ __('All targets') }}</flux:select.option>
            @foreach ($this->targets as $target)
                <flux:select.option value="{{ $target->id }}">{{ $target->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" data-test="create-drive">{{ __('Register drive') }}</flux:button>
    </form>
</section>
