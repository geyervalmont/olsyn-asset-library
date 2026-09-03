<?php

use App\Enums\RunStatus;
use App\Jobs\TrackedJob;
use App\Models\Material;
use App\Models\Representation;
use App\Models\Variant;
use App\Models\WorkerRun;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Jobs')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return WorkerRun::query()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->with(['actor', 'subject'])
            ->orderByDesc('queued_at')
            ->orderByDesc('id')
            ->paginate(40);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return WorkerRun::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function types(): array
    {
        return array_keys((array) config('opal.workers'));
    }

    public function retry(int $runId): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $run = WorkerRun::query()->findOrFail($runId);
        $class = config('opal.workers.'.$run->type);

        if (! is_string($class) || ! is_subclass_of($class, TrackedJob::class)) {
            Flux::toast(variant: 'danger', text: __('Unknown job type :type.', ['type' => $run->type]));

            return;
        }

        $next = $class::requeue($run, auth()->user());

        unset($this->runs, $this->counts);
        Flux::toast(variant: 'success', text: __('Queued again as run :run.', ['run' => substr($next->uuid, 0, 8)]));
    }

    public function subjectUrl(WorkerRun $run): ?string
    {
        $subject = $run->subject;

        return match (true) {
            $subject instanceof Material => route('materials.show', $subject),
            $subject instanceof Variant => route('materials.show', $subject->material),
            $subject instanceof Representation => route('materials.show', $subject->variant->material),
            default => null,
        };
    }

    public function subjectLabel(WorkerRun $run): string
    {
        $subject = $run->subject;

        return match (true) {
            $subject instanceof Material => $subject->code,
            $subject instanceof Variant => $subject->code,
            $subject instanceof Representation => $subject->variant->code.' · '.$subject->target->slug.' '.$subject->quality->slug,
            default => '—',
        };
    }
}; ?>

<section>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Workers') }}</x-ui.eyebrow>
            <h1>{{ __('Jobs') }}</h1>
            <p class="ui-page-head__lede">{{ __('Every piece of background work, what it was asked to do, and what happened. Failed runs can be queued again.') }}</p>
        </div>
        @if (auth()->user()?->isSuperAdmin())
            <div class="ui-page-head__actions">
                <x-ui.button href="{{ url('/horizon') }}" variant="quiet" size="sm">{{ __('Open Horizon') }}</x-ui.button>
            </div>
        @endif
    </div>

    <div class="ui-toolbar">
        <div class="ui-segment" role="group" aria-label="{{ __('Status') }}">
            <button type="button" wire:click="$set('status', '')" @class(['is-active' => $status === ''])>{{ __('All') }}</button>
            @foreach (RunStatus::cases() as $case)
                <button type="button" wire:click="$set('status', '{{ $case->value }}')" @class(['is-active' => $status === $case->value]) data-test="status-{{ $case->value }}">
                    {{ $case->label() }} <span style="opacity: .6">{{ $this->counts[$case->value] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        <select class="ui-select" wire:model.live="type" aria-label="{{ __('Type') }}">
            <option value="">{{ __('All types') }}</option>
            @foreach ($this->types as $type)
                <option value="{{ $type }}">{{ str_replace('_', ' ', $type) }}</option>
            @endforeach
        </select>
    </div>

    @if ($this->runs->isEmpty())
        <x-ui.panel data-test="no-runs">
            <x-ui.empty-state :title="__('Nothing has run yet')" :description="__('Generate tiers from a material, or run opal:tiers:backfill, and runs appear here.')" />
        </x-ui.panel>
    @else
        <div class="ui-data-shell">
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('Run') }}</th>
                            <th>{{ __('Type') }}</th>
                            <th>{{ __('Subject') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Queued') }}</th>
                            <th>{{ __('Duration') }}</th>
                            <th>{{ __('By') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->runs as $run)
                            <tr wire:key="run-{{ $run->id }}" data-test="run-row">
                                <td><span class="ui-code">{{ substr($run->uuid, 0, 8) }}</span></td>
                                <td>{{ str_replace('_', ' ', $run->type) }}</td>
                                <td>
                                    @if ($url = $this->subjectUrl($run))
                                        <a class="ui-code" href="{{ $url }}" wire:navigate>{{ $this->subjectLabel($run) }}</a>
                                    @else
                                        <span class="ui-code">{{ $this->subjectLabel($run) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <x-ui.badge :tone="match ($run->status->value) { 'succeeded' => 'success', 'failed' => 'danger', 'running' => 'info', default => 'neutral' }" dot data-test="run-status">{{ $run->status->label() }}</x-ui.badge>
                                    @if ($run->error)
                                        <div class="ui-code" style="margin-top: 4px; color: var(--ui-danger); max-width: 360px; white-space: normal">{{ \Illuminate\Support\Str::limit($run->error, 160) }}</div>
                                    @elseif ($run->result)
                                        <div class="ui-code" style="margin-top: 4px; max-width: 360px; white-space: normal">
                                            @foreach (($run->result['created'] ?? []) as $item) {{ $item['tier'] ?? '' }} ✓ @endforeach
                                            @foreach (($run->result['skipped'] ?? []) as $item) {{ $item['tier'] ?? '' }} ({{ $item['reason'] ?? '' }}) @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td><time datetime="{{ $run->queued_at->toIso8601String() }}">{{ $run->queued_at->diffForHumans() }}</time></td>
                                <td>{{ $run->duration_ms !== null ? number_format($run->duration_ms / 1000, 1).' s' : '—' }}</td>
                                <td>{{ $run->actor?->name ?? __('System') }}</td>
                                <td>
                                    @if ($run->status->value === 'failed')
                                        <x-ui.button wire:click="retry({{ $run->id }})" variant="secondary" size="sm" data-test="retry">{{ __('Retry') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{ $this->runs->links('vendor.pagination.opal') }}
</section>
