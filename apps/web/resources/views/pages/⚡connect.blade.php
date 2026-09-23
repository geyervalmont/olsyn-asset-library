<?php

use App\Actions\Clients\TouchClientSession;
use App\Actions\Drives\ManageIntake;
use App\Models\DriveIntakeSession;
use App\Models\ClientSession;
use App\Models\DriveConnection;
use App\Support\ClientReleaseCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Connect')] class extends Component
{
    public string $batchName = '';

    #[Computed]
    public function batches(): \Illuminate\Support\Collection
    {
        if (! Auth::user()->can('materials.contribute')) {
            return collect();
        }

        return DriveIntakeSession::query()->where('user_id', Auth::id())->withCount('files')
            ->withCount(['files as uploaded_count' => fn ($query) => $query->whereNotNull('uploaded_at')])->latest()->limit(10)->get();
    }

    public function createBatch(ManageIntake $intake): void
    {
        abort_unless(Auth::user()->can('materials.contribute'), 403);
        $this->validate(['batchName' => ['required', 'string', 'max:120']]);
        $intake->create(Auth::user(), $this->batchName);
        $this->batchName = '';
        unset($this->batches);
    }

    public function cancelBatch(string $uuid, ManageIntake $intake): void
    {
        abort_unless(Auth::user()->can('materials.contribute'), 403);
        $intake->cancel(DriveIntakeSession::query()->where('user_id', Auth::id())->where('uuid', $uuid)->firstOrFail());
        unset($this->batches);
    }

    #[Computed]
    public function release(): ?array
    {
        try {
            return app(ClientReleaseCatalog::class)->latest('revit');
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function sessions(): \Illuminate\Support\Collection
    {
        return ClientSession::query()->where('user_id', Auth::id())->where('platform', 'revit')
            ->whereNull('ended_at')->where('last_seen_at', '>=', now()->subDays(7))->latest('last_seen_at')->get();
    }

    #[Computed]
    public function drives(): \Illuminate\Support\Collection
    {
        return DriveConnection::query()->where('user_id', Auth::id())->latest('last_seen_at')->get();
    }

    public function endSession(int $id, TouchClientSession $touch): void
    {
        $touch->end(ClientSession::query()->where('user_id', Auth::id())->findOrFail($id));
        unset($this->sessions);
    }

    public function disconnectDrive(int $id): void
    {
        $connection = DriveConnection::query()->where('user_id', Auth::id())->findOrFail($id);
        if ($connection->token_id !== null) {
            Auth::user()->tokens()->whereKey($connection->token_id)->delete();
        }
        $connection->update(['state' => 'offline', 'token_id' => null]);
        unset($this->drives);
    }
}; ?>

<section class="ui-connect" data-test="connect-page">
    <div class="ui-connect__hero">
        <div>
            <x-ui.eyebrow>{{ __('Your workspace, connected') }}</x-ui.eyebrow>
            <h1>{{ __('Take your materials into Revit.') }}</h1>
            <p>{{ __('One installation. Sign in with your OPAL account. Manage your connections here.') }}</p>
        </div>
        <div class="ui-connect__account">
            <span class="ui-avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div>
            <x-ui.badge tone="success" dot>{{ __('Signed in') }}</x-ui.badge>
        </div>
    </div>

    <ol class="ui-connect__steps" aria-label="{{ __('Connection steps') }}">
        <li>
            <span class="ui-connect__number" aria-hidden="true">01</span>
            <h2>{{ __('Install OPAL') }}</h2>
            <p>{{ __('Close Revit, then run the Windows installer. It detects your Revit versions for you.') }}</p>
            @if ($this->release)
                <x-ui.button href="{{ $this->release['installer']['url'] }}" data-test="connect-download">{{ __('Download for Windows') }} <span aria-hidden="true">↓</span></x-ui.button>
                <small>{{ __('Version :version · Revit 2024–2027', ['version' => $this->release['version']]) }}</small>
            @else
                <p class="ui-connect__notice" role="status">{{ __('The download service is temporarily unavailable. Please try again shortly.') }}</p>
            @endif
        </li>
        <li>
            <span class="ui-connect__number" aria-hidden="true">02</span>
            <h2>{{ __('Connect your account') }}</h2>
            <p>{{ __('Open Revit → OPAL → Settings → Connect account. Your browser opens to confirm the connection.') }}</p>
            <x-ui.button href="{{ route('link') }}" variant="secondary" wire:navigate>{{ __('Enter a connection code') }}</x-ui.button>
            <small>{{ __('Already installed? Start here. No API token to copy.') }}</small>
        </li>
        <li>
            <span class="ui-connect__number" aria-hidden="true">03</span>
            <h2>{{ __('Choose a material') }}</h2>
            <p>{{ __('Open a Revit project, browse the library, then use Apply in Revit. Your connection appears below.') }}</p>
            <x-ui.button href="{{ route('materials.index') }}" variant="secondary" wire:navigate>{{ __('Browse materials') }} <span aria-hidden="true">→</span></x-ui.button>
            <small>{{ __('Published materials also need an available material drive.') }}</small>
        </li>
    </ol>

    <div class="ui-connect__devices" wire:poll.15s>
        <div class="ui-section-head">
            <div><x-ui.eyebrow>{{ __('Connection check') }}</x-ui.eyebrow><h2>{{ __('Your devices') }}</h2></div>
            <span class="ui-connect__hint">{{ __('Updates automatically') }}</span>
        </div>
        @forelse ($this->sessions as $session)
            <article class="ui-connect__device" wire:key="revit-{{ $session->id }}" data-test="connect-session">
                <div class="ui-connect__device-icon" aria-hidden="true">R</div>
                <div class="ui-connect__device-copy">
                    <strong>{{ $session->machine }}</strong>
                    <span>{{ $session->app_version ?: __('Revit') }} · {{ $session->document ?: __('Open a project to apply a material') }}</span>
                    @if ($session->isLive())
                        @if ($session->drive_status === 'available')
                            <small>{{ __('Material folder available: :path', ['path' => $session->mount_path]) }}</small>
                        @elseif ($session->drive_status === 'missing')
                            <small class="ui-connect__warning">{{ __('Your material folder is unavailable. Check its connection in OPAL Settings in Revit.') }}</small>
                        @else
                            <small>{{ __('Material folder status has not been reported by this connector.') }}</small>
                        @endif
                    @else
                        <small>{{ __('Last seen :when. Open Revit to reconnect.', ['when' => $session->last_seen_at->diffForHumans()]) }}</small>
                    @endif
                </div>
                <x-ui.badge :tone="$session->isLive() ? 'success' : 'neutral'" dot>{{ $session->isLive() ? __('Connected') : __('Offline') }}</x-ui.badge>
                <x-ui.button variant="quiet" size="sm" wire:click="endSession({{ $session->id }})" wire:confirm="{{ __('End this Revit session? Revit can reconnect with its saved account.') }}">{{ __('End session') }}</x-ui.button>
            </article>
        @empty
            <div class="ui-connect__empty" data-test="connect-waiting">
                <span class="ui-connect__device-icon" aria-hidden="true">↔</span>
                <div><strong>{{ __('Waiting for your first connection') }}</strong><p>{{ __('After connecting your account in Revit, this page will show your computer and open project. You can browse the library while you set up.') }}</p></div>
            </div>
        @endforelse
        @foreach ($this->drives as $connection)
            <article class="ui-connect__device" wire:key="drive-{{ $connection->id }}" data-test="connect-drive">
                <div class="ui-connect__device-icon" aria-hidden="true">O</div>
                <div class="ui-connect__device-copy"><strong>{{ __('OPAL Drive · :machine', ['machine' => $connection->machine]) }}</strong>
                    <span>{{ $connection->mount_path ?: __('No drive mounted') }}</span>
                    <small>{{ match ($connection->error_code) {
                        'driver_missing' => __('The drive component needs installing. Contact your IT administrator.'),
                        'mount_in_use' => __('The configured drive letter is already in use.'),
                        'network' => __('Cannot reach OPAL. Check your internet connection or company proxy.'),
                        'sign_in' => __('Sign in again in the OPAL desktop client.'),
                        'access_denied' => __('Your account no longer has access to this drive.'),
                        default => __('Last reported :when', ['when' => $connection->last_seen_at->diffForHumans()]),
                    } }}</small>
                </div>
                <x-ui.badge :tone="$connection->isLive() && $connection->state === 'mounted' ? 'success' : 'neutral'">{{ $connection->isLive() ? ucfirst($connection->state) : __('Offline') }}</x-ui.badge>
                <x-ui.button variant="quiet" size="sm" wire:click="disconnectDrive({{ $connection->id }})" wire:confirm="{{ __('Disconnect this drive and revoke its device access?') }}">{{ __('Disconnect') }}</x-ui.button>
            </article>
        @endforeach
    </div>

    @can('materials.contribute')
        <details wire:ignore.self class="ui-connect__help" data-test="connect-intake">
            <summary>{{ __('Upload batches · Preview') }}</summary>
            <p>{{ __('Prepare a private Incoming folder for the future OPAL Drive client. Only you can access your batch. Drag-and-drop uploading requires the upcoming desktop client; processing and publishing are not available yet.') }}</p>
            <form wire:submit="createBatch" class="ui-connect__batch-form">
                <div><label for="batch-name">{{ __('Batch name') }}</label><input id="batch-name" class="ui-input" wire:model="batchName" maxlength="120" required placeholder="{{ __('e.g. September timber collection') }}">@error('batchName')<p role="alert">{{ $message }}</p>@enderror</div>
                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="createBatch">{{ __('Prepare upload folder') }}</x-ui.button>
            </form>
            @foreach ($this->batches as $batch)
                <article class="ui-connect__device" wire:key="batch-{{ $batch->uuid }}">
                    <div class="ui-connect__device-copy"><strong>{{ $batch->name }}</strong>
                        <small>{{ __(':uploaded of :total files staged', ['uploaded' => $batch->uploaded_count, 'total' => $batch->files_count]) }} · {{ $batch->status === 'submitted' ? __('Waiting for future ingestion') : ($batch->acceptsUploads() ? __('Folder expires :when', ['when' => $batch->expires_at->diffForHumans()]) : __('Folder closed')) }}</small>
                        @if ($batch->acceptsUploads())<code>/Incoming/{{ $batch->uuid }}</code>@endif
                    </div>
                    <x-ui.badge>{{ $batch->status === 'open' && ! $batch->acceptsUploads() ? __('Expired') : ucfirst($batch->status) }}</x-ui.badge>
                    @if ($batch->acceptsUploads())<x-ui.button variant="quiet" size="sm" wire:click="cancelBatch('{{ $batch->uuid }}')" wire:confirm="{{ __('Close this upload folder? Staged files will be retained.') }}">{{ __('Close folder') }}</x-ui.button>@endif
                </article>
            @endforeach
        </details>
    @endcan

    <div class="ui-connect__support">
        <details wire:ignore.self class="ui-connect__help">
            <summary>{{ __('Using a company-managed computer?') }}</summary>
            <p>{{ __('Ask IT to install OPAL for Revit through your software portal. You sign in with your own account after installation.') }}</p>
            <p>{{ __('If installation or connection is blocked, share the setup notes below with your IT team.') }}</p>
            <a href="{{ route('connect.it') }}">{{ __('Open IT setup notes') }} →</a>
        </details>
        <details wire:ignore.self class="ui-connect__help">
            <summary>{{ __('Where is the OPAL virtual drive?') }}</summary>
            <p>{{ __('The Windows virtual-drive client is being prepared. It is not included in the Revit installer yet. Existing material-drive connections continue to work.') }}</p>
            <p>{{ __('You can connect Revit now. Automatic drive mounting and drag-and-drop intake will be added through the desktop client; ingestion processing is not available yet.') }}</p>
        </details>
        <details wire:ignore.self class="ui-connect__help">
            <summary>{{ __('Trouble connecting?') }}</summary>
            <p>{{ __('Keep Revit open and choose OPAL → Settings. Start a new connection if your code has expired. Make sure the account shown in the browser is the one you want to use.') }}</p>
            <div class="ui-actions"><a href="{{ route('link') }}" wire:navigate>{{ __('Enter a new code') }}</a><a href="{{ route('sessions.edit') }}" wire:navigate>{{ __('Manage sessions') }}</a></div>
        </details>
    </div>
</section>
