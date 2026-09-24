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
    public function versions(): array
    {
        try { return app(\App\Support\ExtensionReleases::class)->all(); } catch (\Throwable) { return []; }
    }

    #[Computed]
    public function driveRelease(): ?array
    {
        return collect($this->versions)->firstWhere('client', 'drive');
    }

    public function submitBatch(string $uuid, ManageIntake $intake): void
    {
        abort_unless(Auth::user()->can('materials.contribute'), 403);
        $intake->submit(DriveIntakeSession::query()->where('user_id', Auth::id())->where('uuid', $uuid)->firstOrFail());
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
        return ClientSession::query()->where('user_id', Auth::id())
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
            <h1>{{ __('Take your materials into Revit and Omniverse.') }}</h1>
            <p>{{ __('Mount your material drive, install your extension, and sign in with your OPAL account.') }}</p>
        </div>
        <div class="ui-connect__account">
            <span class="ui-avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div>
            <x-ui.badge tone="success" dot>{{ __('Signed in') }}</x-ui.badge>
        </div>
    </div>

    <x-ui.panel class="ui-connect__versions" data-test="connect-windows-drive">
        <h2>{{ __('Your material folder in Windows') }}</h2>
        <p>{{ __('Install OPAL Drive, choose a drive letter (O: by default), and approve the account connection in your browser. Your materials then open from a normal drive in Explorer, Revit and Omniverse over HTTPS.') }}</p>
        @if ($this->driveRelease)
            @foreach ($this->driveRelease['downloads'] as $download)
                @if (str_ends_with($download['name'], '.exe'))
                    <x-ui.button href="{{ $download['url'] }}" data-test="drive-download">{{ __('Download OPAL Drive') }} ↓</x-ui.button>
                @endif
            @endforeach
            <p><small>{{ __('Version :version · Windows 10/11 x64', ['version' => $this->driveRelease['version']]) }}</small></p>
        @else
            <p>{{ __('The Windows drive installer will appear here when its release is available. Existing material-drive connections continue to work.') }}</p>
        @endif
        <p>{{ __('In Explorer, open materials → by-name to browse by category, material and colourway. Design applications keep their permanent references under by-id.') }}</p>
        <p>{{ __('Installation needs administrator rights once for the filesystem component. Keep OPAL Drive running in the system tray and use the same drive letter across your team. Each app connects with your own account; no VPN or API token to copy.') }}</p>
        <a href="{{ route('connect.it') }}">{{ __('IT installation and network notes') }} →</a>
        <p><a href="{{ route('connect.health') }}" wire:navigate>{{ __('Drive health & error history') }} →</a></p>
    </x-ui.panel>

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
            <p>{{ __('In a Revit project, open OPAL → Browse Library to preview and import a material. Your connection appears below.') }}</p>
            <x-ui.button href="{{ route('materials.index') }}" variant="secondary" wire:navigate>{{ __('Browse materials') }} <span aria-hidden="true">→</span></x-ui.button>
            <small>{{ __('Textures use your material drive or download automatically over HTTPS.') }}</small>
        </li>
    </ol>

    <x-ui.panel class="ui-connect__versions">
        <h2>{{ __('Extension versions') }}</h2>
        <p>{{ __('Install Revit with the Windows installer. For Omniverse, extract the ZIP and add its exts folder in Kit → Window → Extensions → Settings → Extension Search Paths, then enable OPAL Materials.') }}</p>
        <p>{{ __('In either extension, choose Connect account and approve it in your browser. Use Browse Library to search and apply materials.') }}</p>
        @forelse ($this->versions as $version)
            <div class="ui-team__row" wire:key="release-{{ $version['tag'] }}">
                <div class="ui-team__person"><strong>{{ $version['client'] === 'drive' ? 'OPAL Drive' : ucfirst($version['client']) }} {{ $version['version'] }}</strong><small>{{ \Illuminate\Support\Carbon::parse($version['published_at'])->format('j M Y') }}</small></div>
                @foreach ($version['downloads'] as $download)
                    @if (str_ends_with($download['name'], '.exe') || ($version['client'] === 'omniverse' && str_ends_with($download['name'], '.zip')))
                        <x-ui.button variant="secondary" size="sm" href="{{ $download['url'] }}">{{ __('Download') }}</x-ui.button>
                    @endif
                @endforeach
                <a href="{{ $version['url'] }}" target="_blank" rel="noopener">{{ __('Release details & checksums') }}</a>
            </div>
        @empty
            <p>{{ __('Tagged extension versions will appear here automatically after their builds finish.') }}</p>
        @endforelse
    </x-ui.panel>

    <div class="ui-connect__devices" wire:poll.15s>
        <div class="ui-section-head">
            <div><x-ui.eyebrow>{{ __('Connection check') }}</x-ui.eyebrow><h2>{{ __('Your devices') }}</h2></div>
            <span class="ui-connect__hint">{{ __('Updates automatically') }}</span>
        </div>
        @forelse ($this->sessions as $session)
            <article class="ui-connect__device" wire:key="consumer-{{ $session->id }}" data-test="connect-session">
                <div class="ui-connect__device-icon" aria-hidden="true">{{ mb_substr($session->platformLabel(), 0, 1) }}</div>
                <div class="ui-connect__device-copy">
                    <strong>{{ $session->machine }}</strong>
                    <span>{{ $session->platformLabel() }} {{ $session->app_version }} · {{ $session->document ?: __('Open a project to apply a material') }}</span>
                    @if ($session->isLive() && ! $session->supports('material.apply'))
                        <small>{{ __('Update this extension to apply materials from the website.') }}</small>
                    @endif
                    @if ($session->isLive())
                        @if ($session->drive_status === 'available')
                            <small>{{ __('Material folder available: :path', ['path' => $session->mount_path]) }}</small>
                        @elseif ($session->drive_status === 'missing')
                            <small class="ui-connect__warning">{{ __('Using HTTPS downloads. Connect a material drive in the extension to use shared paths.') }}</small>
                        @else
                            <small>{{ __('Material folder status has not been reported by this connector.') }}</small>
                        @endif
                    @else
                        <small>{{ __('Last seen :when. Open the extension to reconnect.', ['when' => $session->last_seen_at->diffForHumans()]) }}</small>
                    @endif
                </div>
                <x-ui.badge :tone="$session->isLive() ? 'success' : 'neutral'" dot>{{ $session->isLive() ? __('Connected') : __('Offline') }}</x-ui.badge>
                <x-ui.button variant="quiet" size="sm" wire:click="endSession({{ $session->id }})" wire:confirm="{{ __('End this application session? Reconnect from its OPAL extension to use it again.') }}">{{ __('End session') }}</x-ui.button>
            </article>
        @empty
            <div class="ui-connect__empty" data-test="connect-waiting">
                <span class="ui-connect__device-icon" aria-hidden="true">↔</span>
                <div><strong>{{ __('Waiting for your first connection') }}</strong><p>{{ __('After connecting your extension, this page will show your computer and open project. You can browse the library while you set up.') }}</p></div>
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
            <p>{{ __('Prepare a private Incoming folder, then copy textures into it through OPAL Drive. Wait until every file is confirmed below before submitting the batch. Only you can access it. Processing and publishing are not available yet.') }}</p>
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
                    @if ($batch->acceptsUploads() && $batch->files_count > 0 && $batch->uploaded_count === $batch->files_count)<x-ui.button variant="secondary" size="sm" wire:click="submitBatch('{{ $batch->uuid }}')" wire:confirm="{{ __('Submit this completed batch? No more files can be added. It will wait for the future ingestion processor.') }}">{{ __('Submit batch') }}</x-ui.button>@endif
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
            <p>{{ __('Install OPAL Drive from this page. It is a separate application from the Revit connector and mounts your account’s materials over HTTPS.') }}</p>
            <p>{{ __('OPAL Drive reconnects at Windows sign-in. Current extensions discover the same-account mount automatically; in earlier extensions, set the material root to your chosen drive letter. Ingestion processing is not available yet.') }}</p>
        </details>
        <details wire:ignore.self class="ui-connect__help">
            <summary>{{ __('Trouble connecting?') }}</summary>
            <p>{{ __('Keep Revit open and choose OPAL → Settings. Start a new connection if your code has expired. Make sure the account shown in the browser is the one you want to use.') }}</p>
            <div class="ui-actions"><a href="{{ route('link') }}" wire:navigate>{{ __('Enter a new code') }}</a><a href="{{ route('sessions.edit') }}" wire:navigate>{{ __('Manage sessions') }}</a></div>
        </details>
    </div>
</section>
