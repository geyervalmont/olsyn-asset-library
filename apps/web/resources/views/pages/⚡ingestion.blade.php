<?php

use App\Actions\Drives\ManageIntake;
use App\Models\DriveIntakeSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Title('Ingestion')] class extends Component {
    use WithPagination;

    #[Url]
    public string $batch = '';
    #[Url]
    public string $status = '';
    #[Url]
    public string $search = '';
    public string $batchName = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
    }

    private function visible(): Builder
    {
        return DriveIntakeSession::query()->visibleTo(auth()->user());
    }

    #[Computed]
    public function batches()
    {
        return $this->visible()->with('user')->withCount('files')
            ->withCount(['files as uploaded_count' => fn ($q) => $q->whereNotNull('uploaded_at')])
            ->withSum('files', 'bytes')
            ->when($this->status === 'open', fn ($q) => $q->open())
            ->when($this->status === 'submitted', fn ($q) => $q->where('status', 'submitted'))
            ->when($this->status === 'closed', fn ($q) => $q->where(fn ($q) => $q->where('status', 'cancelled')
                ->orWhere(fn ($q) => $q->where('status', 'open')->where('expires_at', '<=', now()))))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'ilike', '%'.$this->search.'%'))
            ->orderByDesc('id')->paginate(12);
    }

    #[Computed]
    public function selected(): ?DriveIntakeSession
    {
        abort_if($this->batch !== '' && ! Str::isUuid($this->batch), 404);

        return $this->batch === '' ? null : $this->visible()->with('user')->withCount('files')
            ->withCount(['files as uploaded_count' => fn ($q) => $q->whereNotNull('uploaded_at')])
            ->withSum('files', 'bytes')
            ->withSum(['files as uploaded_bytes' => fn ($q) => $q->whereNotNull('uploaded_at')], 'bytes')
            ->where('uuid', $this->batch)->firstOrFail();
    }

    #[Computed]
    public function files()
    {
        return $this->selected?->files()->orderBy('path')->paginate(50, pageName: 'filesPage');
    }

    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function selectBatch(string $uuid): void
    {
        abort_unless(Str::isUuid($uuid), 404);
        $selected = $this->visible()->where('uuid', $uuid)->firstOrFail();
        $this->batch = $selected->uuid;
        $this->batchName = $selected->name;
        $this->resetPage(pageName: 'filesPage');
        $this->resetErrorBag();
        unset($this->selected, $this->files);
    }

    public function startUpload(ManageIntake $intake): void
    {
        $this->selectBatch($intake->inbox(auth()->user())->uuid);
        $this->status = '';
        $this->search = '';
        $this->resetPage();
        unset($this->batches);
    }

    private function owned(): DriveIntakeSession
    {
        abort_unless(Str::isUuid($this->batch), 404);

        return $this->visible()->where('user_id', auth()->id())->where('uuid', $this->batch)->firstOrFail();
    }

    public function renameBatch(): void
    {
        $this->validate(['batchName' => ['required', 'string', 'max:120']]);
        $this->owned()->update(['name' => trim($this->batchName)]);
        unset($this->selected, $this->batches);
    }

    public function submitBatch(ManageIntake $intake): void
    {
        try {
            $intake->submit($this->owned());
            unset($this->selected, $this->batches, $this->files);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) { throw $error; }
            $this->addError('batch', $error->getMessage());
        }
    }

    public function closeBatch(ManageIntake $intake): void
    {
        $intake->cancel($this->owned());
        unset($this->selected, $this->batches, $this->files);
    }
}; ?>

<section class="ui-ingestion" wire:poll.10s.visible>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Library maintenance') }}</x-ui.eyebrow>
            <h1>{{ __('Ingestion') }}</h1>
            <p class="ui-page-head__lede">{{ __('Collect source files, keep their structure, and prepare them for review.') }}</p>
        </div>
        <div class="ui-page-head__actions">
            <x-ui.button href="{{ route('materials.create') }}" variant="quiet">{{ __('Import a material') }}</x-ui.button>
            <x-ui.button wire:click="startUpload" wire:loading.attr="disabled" wire:target="startUpload">{{ __('Open my upload batch') }}</x-ui.button>
        </div>
    </div>

    <div class="ui-ingestion__intro">
        <div class="ui-ingestion__folder"><x-ui.nav-icon name="import" /></div>
        <div>
            <h2>{{ __('Drop files into') }} <code>OPAL / ingestion / upload</code></h2>
            <p>{{ __('Copy textures, material files, or whole folders using Windows OPAL Drive 0.1.7 or later. Original names and nested folders are preserved. Received files appear here automatically. Older drive versions still use OPAL / upload for the same batch.') }}</p>
            <p class="ui-ingestion__note">{{ __('OPAL / ingestion / workspace is reserved for prepared materials. Workspace generation is not enabled yet. On Nucleus, uploads must go into your linked user and batch folder; files dropped directly into the shared upload root are not collected automatically.') }}</p>
            <p class="ui-ingestion__note">{{ __('Uploads stay private until you queue the batch for your workspace reviewers. Parsing, AI fixes, and publishing will come next.') }}</p>
            <p class="ui-ingestion__note">{{ __('Up to 500 files and 2 GiB per batch · 256 MiB per file') }}</p>
        </div>
        <a href="{{ route('connect') }}" wire:navigate>{{ __('Get OPAL Drive') }} →</a>
    </div>

    <div class="ui-toolbar">
        <div class="ui-segment" role="group" aria-label="{{ __('Batch status') }}">
            @foreach (['' => 'All batches', 'open' => 'Receiving', 'submitted' => 'Queued', 'closed' => 'Closed'] as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')" @class(['is-active' => $status === $value])>{{ __($label) }}</button>
            @endforeach
        </div>
        <input class="ui-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find a batch…') }}" aria-label="{{ __('Find a batch') }}" />
    </div>

    <div class="ui-ingestion__workspace">
        <div class="ui-ingestion__batches">
            @forelse ($this->batches as $item)
                <button type="button" wire:key="batch-{{ $item->uuid }}" wire:click="selectBatch('{{ $item->uuid }}')" @class(['ui-ingestion__batch', 'is-selected' => $batch === $item->uuid]) aria-pressed="{{ $batch === $item->uuid ? 'true' : 'false' }}">
                    <div class="ui-ingestion__batch-head"><strong>{{ $item->name }}</strong><span aria-hidden="true">↗</span></div>
                    <span>{{ $item->uploaded_count }} / {{ $item->files_count }} {{ __('files received') }} · {{ Number::fileSize((int) $item->files_sum_bytes) }}</span>
                    <progress value="{{ $item->uploaded_count }}" max="{{ max(1, $item->files_count) }}" aria-label="{{ __('Files received') }}"></progress>
                    <div class="ui-ingestion__batch-foot"><x-ui.badge :tone="$item->status === 'submitted' ? 'success' : 'neutral'" dot>{{ __($item->displayStatus()) }}</x-ui.badge><small>{{ $item->created_at->diffForHumans() }}</small></div>
                    <small>{{ $item->user->name }} · {{ substr($item->uuid, -8) }}</small>
                </button>
            @empty
                <div class="ui-ingestion__empty">
                    <x-ui.nav-icon name="import" />
                    <h2>{{ __('A clear place to start') }}</h2>
                    <p>{{ $search !== '' || $status !== '' ? __('No batches match this view.') : __('Open your upload batch, then drop files into the upload folder on OPAL Drive.') }}</p>
                </div>
            @endforelse
            {{ $this->batches->links() }}
        </div>

        <div class="ui-ingestion__detail">
            @if ($selected = $this->selected)
                <div class="ui-ingestion__detail-head">
                    <x-ui.eyebrow>{{ __('Source batch') }} · {{ substr($selected->uuid, -8) }}</x-ui.eyebrow>
                    <h2>{{ $selected->name }}</h2>
                    <p>{{ $selected->user->name }} · {{ $selected->created_at->format('d M Y, H:i') }} UTC</p>
                    <x-ui.badge :tone="$selected->status === 'submitted' ? 'success' : 'neutral'" dot>{{ __($selected->displayStatus()) }}</x-ui.badge>
                    @if ($selected->acceptsUploads())
                        <p>{{ __('Drive folder') }} <code>{{ $selected->drivePath() }}</code></p>
                    @endif
                </div>
                <div class="ui-ingestion__metrics">
                    <div><strong>{{ $selected->uploaded_count }}</strong><span>{{ __('Files received') }}</span></div>
                    <div><strong>{{ $selected->files_count - $selected->uploaded_count }}</strong><span>{{ __('Awaiting upload') }}</span></div>
                    <div><strong>{{ Number::fileSize((int) $selected->uploaded_bytes) }}</strong><span>{{ __('Safely stored') }}</span></div>
                </div>
                @if ($selected->user_id === auth()->id())
                    <form class="ui-ingestion__rename" wire:submit="renameBatch" wire:key="rename-{{ $selected->uuid }}" x-data x-init="$wire.batchName = @js($selected->name)">
                        <input class="ui-input" wire:model="batchName" aria-label="{{ __('Batch name') }}" maxlength="120" required />
                        <x-ui.button type="submit" variant="quiet" size="sm">{{ __('Rename') }}</x-ui.button>
                    </form>
                    @error('batchName')<p class="ui-ingestion__error" role="alert">{{ $message }}</p>@enderror
                    @if ($selected->acceptsUploads())
                        <div class="ui-ingestion__actions">
                            <x-ui.button wire:click="submitBatch" wire:loading.attr="disabled" wire:confirm="{{ __('Finished copying? This closes the batch and shares its files with your workspace reviewers. A fresh upload folder will appear on your drive. No processing starts yet.') }}" :disabled="$selected->files_count === 0 || $selected->uploaded_count !== $selected->files_count">{{ __('Queue for review') }}</x-ui.button>
                            <x-ui.button variant="quiet" wire:click="closeBatch" wire:confirm="{{ __('Close this batch? Received files and local copies are retained. Incomplete uploads will stop.') }}">{{ __('Close batch') }}</x-ui.button>
                        </div>
                    @endif
                @endif
                @error('batch')<p class="ui-ingestion__error" role="alert">{{ $message }}</p>@enderror
                <p class="ui-ingestion__hint">{{ $selected->status === 'submitted' ? __('Ready for the future ingestion pipeline. Your source files have not been changed or published.') : __('The list includes files announced by the drive. Finish copying and wait for all uploads to be confirmed before queueing for review.') }}</p>
                <div class="ui-table-scroll">
                    <table class="ui-table ui-ingestion__files">
                        <thead><tr><th>{{ __('Original path') }}</th><th>{{ __('Size') }}</th><th>{{ __('Status') }}</th><th><span class="sr-only">{{ __('Download') }}</span></th></tr></thead>
                        <tbody>
                            @forelse ($this->files as $file)
                                <tr wire:key="file-{{ $file->uuid }}">
                                    <td><span class="ui-ingestion__type">{{ strtoupper(pathinfo($file->path, PATHINFO_EXTENSION)) ?: 'FILE' }}</span><span class="ui-ingestion__path">{{ $file->path }}</span></td>
                                    <td>{{ Number::fileSize($file->bytes) }}</td>
                                    <td><x-ui.badge :tone="$file->uploaded_at ? 'success' : 'neutral'">{{ $file->uploaded_at ? __('Received') : __('Awaiting upload') }}</x-ui.badge></td>
                                    <td>@if ($file->uploaded_at)<a class="ui-ingestion__download" href="{{ route('ingestion.download', ['batch' => $selected->uuid, 'file' => $file->uuid]) }}" aria-label="{{ __('Download :name', ['name' => $file->path]) }}">↓</a>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="ui-ingestion__waiting">{{ __('Waiting for files from OPAL Drive…') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $this->files->links() }}
            @else
                <div class="ui-ingestion__empty ui-ingestion__empty--detail">
                    <x-ui.nav-icon name="materials" />
                    <h2>{{ __('Every source, kept together') }}</h2>
                    <p>{{ __('Select a batch to inspect its files and upload status. Loose images, nested folders, MDL, USD, and other source files can all wait here for review.') }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
