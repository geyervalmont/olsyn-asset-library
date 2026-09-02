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

<section>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Delivery') }}</x-ui.eyebrow>
            <h1>{{ __('Drives') }}</h1>
            <p class="ui-page-head__lede">{{ __('A drive is a namespace PrismFS projects: the current version of every material it can see, at the targets it serves.') }}</p>
        </div>
    </div>

    @if ($issuedToken !== null)
        <x-ui.panel tone="amber" style="margin-bottom: 12px" data-test="issued-token">
            <div class="ui-panel__heading"><div><h3>{{ __('Token for :drive, shown once', ['drive' => $issuedFor]) }}</h3><p>{{ __('Store it with the PrismFS host; only its hash is kept here.') }}</p></div></div>
            <code class="ui-token">{{ $issuedToken }}</code>
            <p class="ui-variant__attrs" style="margin-top: 10px">{{ __('Mount it with:') }}</p>
            <code class="ui-token">prismfs mount --manifest-url {{ route('prismfs.drives.manifest', $issuedFor) }} --manifest-token {{ $issuedToken }}</code>
        </x-ui.panel>
    @endif

    @if ($this->drives->isEmpty())
        <x-ui.panel data-test="no-drives">
            <x-ui.empty-state :title="__('No drives registered yet')" :description="__('Register one below, issue its token, and mount it with PrismFS.')" />
        </x-ui.panel>
    @else
        <div class="ui-data-shell">
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('Drive') }}</th>
                            <th>{{ __('Root') }}</th>
                            <th>{{ __('Target') }}</th>
                            <th>{{ __('Files') }}</th>
                            <th>{{ __('Token') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->drives as $row)
                            <tr wire:key="drive-{{ $row['drive']->id }}" data-test="drive-row">
                                <td><strong style="color: var(--ui-ink)">{{ $row['drive']->name }}</strong> <span class="ui-code">{{ $row['drive']->slug }}</span></td>
                                <td><span class="ui-code">{{ $row['drive']->root_path }}</span></td>
                                <td>{{ $row['drive']->target?->name ?? __('All targets') }}</td>
                                <td data-test="drive-entries">{{ $row['entries'] }}</td>
                                <td>{{ $row['drive']->hasToken() ? __('Issued :when', ['when' => $row['drive']->token_issued_at?->diffForHumans()]) : __('None') }}</td>
                                <td>
                                    <span class="ui-actions">
                                        <x-ui.button :href="route('drives.manifest', $row['drive'])" variant="quiet" size="sm">{{ __('Manifest') }}</x-ui.button>
                                        <x-ui.button wire:click="issueToken({{ $row['drive']->id }})" variant="secondary" size="sm" data-test="issue-token">{{ $row['drive']->hasToken() ? __('Rotate token') : __('Issue token') }}</x-ui.button>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <x-ui.panel tone="paper" style="margin-top: 12px">
        <form wire:submit="create" class="ui-inline-form">
            <x-ui.field :label="__('Name')" for="drive-name" :error="$errors->first('name')">
                <input id="drive-name" class="ui-input" wire:model="name" placeholder="Studio Revit share" data-test="drive-name" />
            </x-ui.field>
            <x-ui.field :label="__('Root path')" for="drive-root" :error="$errors->first('root_path')">
                <input id="drive-root" class="ui-input" wire:model="root_path" data-test="drive-root" />
            </x-ui.field>
            <x-ui.field :label="__('Target')" for="drive-target">
                <select id="drive-target" class="ui-select" wire:model="target_id" data-test="drive-target">
                    <option value="">{{ __('All targets') }}</option>
                    @foreach ($this->targets as $target)
                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.button type="submit" data-test="create-drive">{{ __('Register drive') }}</x-ui.button>
        </form>
    </x-ui.panel>
</section>
