<?php

use App\Actions\Workspace\ManageTeam;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Team')] class extends Component
{
    use WithPagination;

    public string $email = '';
    public string $role = 'editor';
    public string $search = '';
    public string $notice = '';

    public function mount(): void
    {
        $this->workspace();
    }

    protected function workspace(): Tenant
    {
        abort_unless(config('opal.workspace.single'), 404);
        $tenant = Auth::user()->resolveCurrentTenant();
        abort_if($tenant === null, 403);
        app(ManageTeam::class)->authorize(Auth::user(), $tenant);
        return $tenant;
    }

    #[Computed]
    public function members(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->workspace()->users()->when($this->search !== '', fn ($query) => $query->where(fn ($query) =>
            $query->where('name', 'ilike', '%'.$this->search.'%')->orWhere('email', 'ilike', '%'.$this->search.'%')))
            ->orderBy('name')->paginate(25);
    }

    #[Computed]
    public function invitations(): \Illuminate\Support\Collection
    {
        return WorkspaceAccess::query()->where('tenant_id', $this->workspace()->id)->where('status', 'pending')->latest()->limit(50)->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function invite(ManageTeam $team): void
    {
        $this->email = strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', Rule::enum(Role::class)]]);
        $team->invite(Auth::user(), $this->workspace(), $this->email, Role::from($this->role));
        $this->notice = __('Access prepared for :email. Share the sign-in link below; no email has been sent.', ['email' => $this->email]);
        $this->email = '';
        unset($this->invitations);
    }

    public function changeRole(int $id, string $role, ManageTeam $team): void
    {
        $this->resetErrorBag();
        validator(['role' => $role], ['role' => ['required', Rule::enum(Role::class)]])->validate();
        $team->changeRole(Auth::user(), $this->workspace(), User::findOrFail($id), Role::from($role));
        if ($id === Auth::id() && $role !== 'admin') {
            $this->redirectRoute('materials.index', navigate: true);
            $this->skipRender();
            return;
        }
        $this->notice = __('Role updated. Olsyn account permissions still apply.');
        unset($this->members);
    }

    public function removeMember(int $id, ManageTeam $team): void
    {
        $this->resetErrorBag();
        $team->remove(Auth::user(), $this->workspace(), User::findOrFail($id));
        if ($id === Auth::id()) {
            $this->redirectRoute('dashboard', navigate: true);
            $this->skipRender();
            return;
        }
        $this->notice = __('Team access removed. Automatic enrollment will not add this person back.');
        unset($this->members);
    }

    public function cancelInvitation(int $id, ManageTeam $team): void
    {
        $team->cancel(Auth::user(), $this->workspace(), $id);
        $this->notice = __('Invitation cancelled. This email will need a new invitation to join.');
        unset($this->invitations);
    }
}; ?>

<section class="ui-team" data-test="team-page">
    <div class="ui-page-head"><div><x-ui.eyebrow>{{ __('People & access') }}</x-ui.eyebrow><h1>{{ __('Your team') }}</h1>
        <p class="ui-page-head__lede">{{ __('One shared library. Add a teammate, share the sign-in link, and they’re ready to get started.') }}</p></div></div>

    @if ($notice)<div class="ui-team__notice" role="status">{{ $notice }}</div>@endif
    @error('team')<div class="ui-team__notice ui-team__notice--error" role="alert">{{ $message }}</div>@enderror
    @error('role')<div role="alert">{{ $message }}</div>@enderror

    <div class="ui-team__setup">
        <x-ui.panel>
            <h2>{{ __('Add a teammate') }}</h2>
            <p class="ui-team__hint">{{ __('Use the email they will sign in with. Their role is applied automatically after they verify that account. Invitations expire after seven days.') }}</p>
            <form wire:submit="invite" class="ui-team__invite">
                <div><label for="team-email">{{ __('Email address') }}</label><input class="ui-input" id="team-email" type="email" wire:model="email" required maxlength="255" placeholder="name@company.com" autocomplete="off">@error('email')<p class="ui-team__error" role="alert">{{ $message }}</p>@enderror</div>
                <div><label for="team-role">{{ __('Role') }}</label><select class="ui-select" id="team-role" wire:model="role"><option value="viewer">{{ __('Viewer') }}</option><option value="editor">{{ __('Designer') }}</option><option value="admin">{{ __('Administrator') }}</option></select></div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="invite">{{ __('Prepare invitation') }}</x-ui.button>
            </form>
            <p class="ui-team__hint">{{ __('Viewer: browse and use materials. Designer: also create and review. Administrator: manage people and publish.') }}</p>
            @if (config('olsyn_access.enabled'))<p class="ui-team__hint">{{ __('The person also needs OPAL access in Olsyn. Their Olsyn permissions may limit what this role allows.') }}</p>@endif
        </x-ui.panel>
        <x-ui.panel>
            <h2>{{ __('Share one sign-in link') }}</h2>
            <p class="ui-team__hint">{{ __('Send this link to your teammate through your usual channel. OPAL recognises their verified account and takes them to setup.') }}</p>
            <div x-data="{ copied: false, failed: false }" class="ui-team__share">
                <label for="team-link" class="sr-only">{{ __('Team sign-in link') }}</label><input id="team-link" class="ui-input" readonly value="{{ route('workspace.join') }}" x-ref="link" x-on:focus="$el.select()">
                <x-ui.button type="button" variant="secondary" x-on:click="failed = false; navigator.clipboard.writeText($refs.link.value).then(() => { copied = true; setTimeout(() => copied = false, 3000) }).catch(() => { $refs.link.focus(); $refs.link.select(); failed = true })"><span x-text="copied ? 'Copied' : 'Copy link'">{{ __('Copy link') }}</span></x-ui.button>
                <small x-cloak x-show="failed" role="status">{{ __('Select and copy the link above.') }}</small>
            </div>
            @if(config('opal.workspace.auto_join') && config('olsyn_access.enabled'))
                <p class="ui-team__hint">{{ __('People already approved for OPAL in Olsyn join automatically as viewers or designers. Administrator access always requires an explicit role assignment.') }}</p>
            @endif
            <a href="{{ route('connect.it') }}" class="ui-team__setup-link">{{ __('IT installation notes') }} →</a>
        </x-ui.panel>
    </div>

    @if ($this->invitations->isNotEmpty())
        <div class="ui-section-head"><h2>{{ __('Waiting to join') }}</h2></div>
        <div class="ui-team__rows">
            @foreach ($this->invitations as $invitation)
                <article class="ui-team__row" wire:key="invitation-{{ $invitation->id }}">
                    <div class="ui-team__person"><strong>{{ $invitation->email }}</strong><small>{{ match($invitation->role) { 'editor' => __('Designer'), 'admin' => __('Administrator'), default => __('Viewer') } }} · {{ $invitation->expires_at->isPast() ? __('Expired — prepare a new invitation') : __('Expires :when', ['when' => $invitation->expires_at->diffForHumans()]) }}</small></div>
                    <x-ui.badge :tone="$invitation->expires_at->isPast() ? 'neutral' : 'warning'">{{ $invitation->expires_at->isPast() ? __('Expired') : __('Pending') }}</x-ui.badge>
                    <x-ui.button variant="quiet" size="sm" wire:click="cancelInvitation({{ $invitation->id }})" wire:confirm="{{ __('Cancel this invitation?') }}">{{ __('Cancel') }}</x-ui.button>
                </article>
            @endforeach
        </div>
    @endif

    <div class="ui-section-head ui-team__members-head"><h2>{{ __('Members') }} <span class="ui-team__count">{{ $this->members->total() }}</span></h2><div><label for="team-search" class="sr-only">{{ __('Find a teammate') }}</label><input id="team-search" class="ui-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find a teammate…') }}"></div></div>
    <div class="ui-team__rows">
        @forelse ($this->members as $member)
            @php $memberRole = $member->roleIn(Tenant::current()); @endphp
            <article class="ui-team__row" wire:key="member-{{ $member->id }}">
                <span class="ui-avatar" aria-hidden="true">{{ $member->initials() }}</span>
                <div class="ui-team__person"><strong>{{ $member->name }} @if($member->id === auth()->id())<small>{{ __('(you)') }}</small>@endif</strong><small>{{ $member->email }}</small></div>
                @if ($member->isSuperAdmin())
                    <x-ui.badge>{{ __('Global administrator') }}</x-ui.badge>
                @else
                    <label class="sr-only" for="member-role-{{ $member->id }}">{{ __('Role for :name', ['name' => $member->name]) }}</label>
                    <select id="member-role-{{ $member->id }}" class="ui-select" wire:change="changeRole({{ $member->id }}, $event.target.value)" wire:loading.attr="disabled">
                        @foreach (['viewer' => __('Viewer'), 'editor' => __('Designer'), 'admin' => __('Administrator')] as $value => $label)<option value="{{ $value }}" @selected($memberRole?->value === $value)>{{ $label }}</option>@endforeach
                    </select>
                    <x-ui.button variant="quiet" size="sm" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the team? They will lose this workspace’s access.', ['name' => $member->name]) }}">{{ __('Remove') }}</x-ui.button>
                @endif
            </article>
        @empty
            <p class="ui-team__hint">{{ __('No teammates match your search.') }}</p>
        @endforelse
    </div>
    <div style="margin-top: 16px">{{ $this->members->links() }}</div>
</section>
