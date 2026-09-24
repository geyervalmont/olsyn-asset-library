<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Tenants\AddTenantMember;
use App\Actions\Tenants\RemoveTenantMember;
use App\Actions\Workspace\JoinSharedWorkspace;
use App\Actions\Workspace\ManageTeam;
use App\Enums\Role;
use App\Jobs\Workspace\SendInvitation;
use App\Mail\WorkspaceWelcome;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccess;
use App\Services\OlsynAccess;
use App\Support\SharedWorkspace;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    config(['opal.workspace.single' => true, 'opal.workspace.auto_join' => true, 'opal.workspace.slug' => 'shared-test', 'olsyn_access.enabled' => false]);
    $this->workspace = Tenant::factory()->create(['slug' => 'shared-test']);
    $this->workspace->makeCurrent();
    $this->admin = User::factory()->withTenant($this->workspace, Role::Admin)->create();
    $this->viewer = User::factory()->withTenant($this->workspace, Role::Viewer)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

test('members land directly in the library with no workspace switcher', function () {
    $this->actingAs($this->viewer)->get('/dashboard')->assertRedirect(route('materials.index'));
    $this->get('/materials')->assertOk()->assertSee('data-test="shared-library"', false)
        ->assertDontSee('data-test="workspace-switcher"', false)->assertDontSee('Your workspaces')->assertDontSee('href="'.route('workspace.team').'"', false);
    $this->actingAs($this->admin)->get('/team')->assertOk()->assertSee('Add a teammate')->assertSee('Share one sign-in link');
    $this->actingAs($this->viewer)->get('/team')->assertForbidden();
    $this->get('/join')->assertRedirect(route('connect'));
});

test('unapproved accounts get a useful access screen without automatic local enrollment', function () {
    $new = User::factory()->create();
    $this->actingAs($new)->get('/dashboard')->assertOk()->assertSee('Your team access is being set up')->assertSee($new->email);
    $this->get('/materials')->assertRedirect(route('dashboard'));
    expect($new->tenants()->count())->toBe(0);
});

test('an email invitation is accepted automatically only by the verified matching account', function () {
    app(ManageTeam::class)->invite($this->admin, $this->workspace, '  Designer@Example.test ', Role::Editor);
    $invitation = WorkspaceAccess::sole();
    expect($invitation->email)->toBe('designer@example.test');
    $wrong = User::factory()->create();
    expect(app(JoinSharedWorkspace::class)->handle($wrong))->toBeNull();
    $new = User::factory()->unverified()->create(['email' => 'designer@example.test']);
    expect(app(JoinSharedWorkspace::class)->handle($new))->toBeNull();
    $new->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($new)->get('/join')->assertRedirect(route('connect'));
    expect($new->fresh()->current_tenant_id)->toBe($this->workspace->id)
        ->and($new->roleIn($this->workspace))->toBe(Role::Editor)
        ->and($invitation->fresh()->status)->toBe('active');
    $this->get('/materials')->assertOk();
    expect($new->tenants()->count())->toBe(1);
});

test('Olsyn-approved people auto join with limited roles and never become administrators automatically', function () {
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnUsing(fn ($user, $permission) => in_array($permission, ['materials.view', 'materials.contribute'], true));
    $new = User::factory()->create();
    $this->actingAs($new)->get('/dashboard')->assertRedirect(route('materials.index'));
    expect($new->roleIn($this->workspace))->toBe(Role::Editor);
    // Existing local role remains authoritative even when central access changes.
    app(AddTenantMember::class)->handle($this->workspace, $new, Role::Viewer);
    app(JoinSharedWorkspace::class)->handle($new);
    expect($new->roleIn($this->workspace))->toBe(Role::Viewer);
});

test('central approval is still required and local invitations cannot bypass a denial', function () {
    app(ManageTeam::class)->invite($this->admin, $this->workspace, 'new@example.test', Role::Admin);
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnFalse();
    $new = User::factory()->create(['email' => 'new@example.test']);
    $this->actingAs($new)->get('/join')->assertForbidden();
    expect($new->tenants()->exists())->toBeFalse()->and(WorkspaceAccess::sole()->status)->toBe('pending');
});

test('removed members remain removed despite automatic Olsyn enrollment until explicitly reinvited', function () {
    $member = User::factory()->withTenant($this->workspace, Role::Editor)->create();
    app(ManageTeam::class)->remove($this->admin, $this->workspace, $member);
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnTrue();
    expect(app(JoinSharedWorkspace::class)->handle($member))->toBeNull();
    $this->actingAs($member)->get('/materials')->assertRedirect(route('dashboard'));
    expect($member->isMemberOf($this->workspace))->toBeFalse();
    app(ManageTeam::class)->invite($this->admin, $this->workspace, $member->email, Role::Viewer);
    app(JoinSharedWorkspace::class)->handle($member);
    expect($member->roleIn($this->workspace))->toBe(Role::Viewer);
});

test('expired and cancelled invitations cannot join and require a fresh approval', function () {
    $new = User::factory()->create();
    app(ManageTeam::class)->invite($this->admin, $this->workspace, $new->email, Role::Editor);
    $this->travel(8)->days();
    expect(app(JoinSharedWorkspace::class)->handle($new))->toBeNull();
    app(ManageTeam::class)->invite($this->admin, $this->workspace, $new->email, Role::Viewer);
    app(ManageTeam::class)->cancel($this->admin, $this->workspace, WorkspaceAccess::sole()->id);
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnTrue();
    expect(app(JoinSharedWorkspace::class)->handle($new))->toBeNull();
});

test('only administrators can manage membership and the last administrator is protected', function () {
    expect(fn () => app(ManageTeam::class)->invite($this->viewer, $this->workspace, 'no@example.test', Role::Admin))->toThrow(HttpException::class);
    expect(fn () => app(ManageTeam::class)->changeRole($this->admin, $this->workspace, $this->admin, Role::Viewer))->toThrow(ValidationException::class);
    expect(fn () => app(ManageTeam::class)->remove($this->admin, $this->workspace, $this->admin))->toThrow(ValidationException::class);
    $other = User::factory()->withTenant(Tenant::factory()->create(), Role::Viewer)->create();
    expect(fn () => app(ManageTeam::class)->remove($this->admin, $this->workspace, $other))->toThrow(HttpException::class);
    expect($this->admin->isMemberOf($this->workspace))->toBeTrue();
});

test('single workspace mode rejects switching to another workspace and never guesses among several', function () {
    $other = Tenant::factory()->create();
    $this->admin->tenants()->attach($other);
    $this->actingAs($this->admin)->post(route('tenants.switch', $other))->assertNotFound();
    config(['opal.workspace.slug' => null]);
    expect(app(SharedWorkspace::class)->tenant())->toBeNull();
    config(['opal.workspace.slug' => 'missing-workspace']);
    expect(app(JoinSharedWorkspace::class)->handle($this->admin))->toBeNull();
});

test('the Team page sends an invitation and applies role changes', function () {
    Mail::fake();
    Livewire::actingAs($this->admin)->test('pages::team')->set('email', 'DESIGN@EXAMPLE.TEST')->set('role', 'editor')->call('invite')->assertHasNoErrors()->assertSee('design@example.test')->assertSee('Invitation queued');
    expect(WorkspaceAccess::sole()->role)->toBe('editor');
    Mail::assertSent(WorkspaceWelcome::class, fn ($mail) => $mail->hasTo('design@example.test'));
    Livewire::actingAs($this->admin)->test('pages::team')->call('changeRole', $this->viewer->id, 'editor')->assertHasNoErrors();
    expect($this->viewer->roleIn($this->workspace))->toBe(Role::Editor);
    Livewire::actingAs($this->admin)->test('pages::team')->set('email', $this->viewer->email)->call('invite')->assertHasErrors('email');
});

test('the shared sign-in link goes directly to Olsyn and preserves connector setup', function () {
    config(['olsyn_access.enabled' => true]);
    $this->get('/join')->assertRedirect(route('olsyn.login', ['return_to' => '/connect']));
    $this->get('/login')->assertOk()->assertSee('Welcome to OPAL')->assertSee('Use an existing OPAL password or passkey')->assertDontSee('Don’t have an account?');
    config(['olsyn_access.enabled' => false]);
    $this->get('/join')->assertRedirect(route('login'))->assertSessionHas('url.intended', route('connect'));
});

test('invitation-only mode and central read-only approval do not broaden access', function () {
    config(['olsyn_access.enabled' => true, 'opal.workspace.auto_join' => false]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnUsing(fn ($user, $permission) => $permission === 'materials.view');
    $new = User::factory()->create();
    expect(app(JoinSharedWorkspace::class)->handle($new))->toBeNull();
    config(['opal.workspace.auto_join' => true]);
    app(JoinSharedWorkspace::class)->handle($new);
    expect($new->roleIn($this->workspace))->toBe(Role::Viewer);
});

test('membership removal through the existing action also blocks automatic reenrollment', function () {
    app(RemoveTenantMember::class)->handle($this->workspace, $this->viewer);
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnTrue();
    expect(app(JoinSharedWorkspace::class)->handle($this->viewer))->toBeNull();
});

test('personal-drive onboarding uses the shared workspace and rejects a removed member on the next request', function () {
    config(['olsyn_access.enabled' => true]);
    $this->mock(OlsynAccess::class)->shouldReceive('allows')->andReturnUsing(fn ($user, $permission) => $permission === 'materials.view');
    $new = User::factory()->create();
    Sanctum::actingAs($new, ['drive:read']);
    $this->getJson('/api/v1/drive/bootstrap')->assertOk()->assertJsonPath('data.capabilities.intake', false);
    expect($new->roleIn($this->workspace))->toBe(Role::Viewer);
    app(RemoveTenantMember::class)->handle($this->workspace, $new);
    $this->getJson('/api/v1/drive/bootstrap')->assertForbidden();
});

test('invitation delivery is observable and cancelled or superseded jobs cannot send', function () {
    Mail::fake();
    Queue::fake();
    $team = app(ManageTeam::class);
    $team->invite($this->admin, $this->workspace, 'delivery@example.test', Role::Editor);
    $entry = WorkspaceAccess::sole();
    $job = new SendInvitation($entry->id, $entry->email_queued_at->toDateTimeString());
    $job->handle();
    Mail::assertSent(WorkspaceWelcome::class, fn ($mail) => $mail->hasTo('delivery@example.test'));
    expect($entry->fresh()->email_sent_at)->not->toBeNull();
    $job->handle();
    Mail::assertSentCount(1);
    expect(fn () => $team->invite($this->admin, $this->workspace, $entry->email, Role::Editor))->toThrow(ValidationException::class);
    $this->travel(2)->minutes();
    $team->invite($this->admin, $this->workspace, $entry->email, Role::Editor);
    $job->handle();
    Mail::assertSentCount(1);
    $entry->refresh();
    $team->cancel($this->admin, $this->workspace, $entry->id);
    (new SendInvitation($entry->id, $entry->email_queued_at->toDateTimeString()))->handle();
    Mail::assertSentCount(1);
});
