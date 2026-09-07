<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Middleware\EnsureOlsynAccess;
use App\Http\Middleware\UseCurrentTenant;
use App\Library\Conversion\ConverterRegistry;
use App\Library\Conversion\OmniverseMdlConverter;
use App\Library\Conversion\RevitImageSetConverter;
use App\Models\Drive;
use App\Models\File;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\Representation;
use App\Models\User;
use App\Models\Variant;
use App\Models\WorkerRun;
use App\Services\OlsynAccess;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConverterRegistry::class, function (): ConverterRegistry {
            $registry = new ConverterRegistry;
            $registry->register($this->app->make(RevitImageSetConverter::class));
            $registry->register($this->app->make(OmniverseMdlConverter::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureMorphMap();
        $this->configureApiDocs();

        Livewire::addPersistentMiddleware([
            UseCurrentTenant::class,
            EnsureOlsynAccess::class,
            NeedsTenant::class,
            EnsureValidTenantSession::class,
        ]);
    }

    /**
     * Every API route takes a Sanctum bearer token.
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    /**
     * Stable morph aliases for library records referenced polymorphically
     * (aliases, tags, and later provenance and embeddings).
     */
    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'material' => Material::class,
            'variant' => Variant::class,
            'file' => File::class,
            'provenance_event' => ProvenanceEvent::class,
            'representation' => Representation::class,
            'drive' => Drive::class,
            'worker_run' => WorkerRun::class,
        ]);
    }

    /**
     * Super-admins pass every ability, before roles and tenant scoping apply.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            // A central permission is a ceiling: it never bypasses local workspace policy.
            $permission = Permission::tryFrom($ability);
            if ($permission && ! app(OlsynAccess::class)->allows($user, $ability)) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
