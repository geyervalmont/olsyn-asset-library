<?php

use App\Actions\Packaging\AssembleBuildRequest;
use App\Actions\Packaging\PackageVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Library\Packaging\BuildRequest;
use App\Library\Packaging\BuiltPackage;
use App\Library\Packaging\PackageBuilder;
use App\Library\Packaging\PendingToolbox;
use App\Models\File;
use App\Models\User;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    Storage::fake(config('opal.packages_disk'));
});

/**
 * A builder that writes a real file, so the action's storage path is exercised
 * rather than mocked around.
 */
function fakeBuilder(array &$calls, array $losses = []): PackageBuilder
{
    return new class($calls, $losses) implements PackageBuilder
    {
        public function __construct(private array &$calls, private array $losses) {}

        public function name(): string
        {
            return 'fake';
        }

        public function version(): string
        {
            return '1.2.3';
        }

        public function available(): bool
        {
            return true;
        }

        public function build(BuildRequest $request): BuiltPackage
        {
            $this->calls[] = $request->variantCode;
            $path = tempnam(sys_get_temp_dir(), 'usdz');
            file_put_contents($path, 'PK-not-really-a-package');

            return new BuiltPackage(
                path: $path,
                sha256: hash('sha256', $request->digest().count($this->calls)),
                bytes: (int) filesize($path),
                tiers: $request->tiers(),
                losses: $this->losses,
                builder: $this->name(),
                builderVersion: $this->version(),
            );
        }
    };
}

/**
 * The rung a file lands on comes from its actual pixels, not from the tier it
 * was filed under, so tests have to give their files real dimensions.
 */
function approvedCanonical(Variant $variant, string $tier = '4k', int $pixels = 4096, array $extra = []): void
{
    $representation = app(CreateRepresentation::class)->handle($variant, 'pbr', $tier, array_merge([
        'base_color' => File::factory()->create(['colour_space' => 'srgb', 'width_px' => $pixels, 'height_px' => $pixels]),
        'normal' => File::factory()->create(['colour_space' => null, 'width_px' => $pixels, 'height_px' => $pixels]),
    ], $extra), metadata: ['normal_convention' => 'opengl']);

    app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved, User::factory()->create());
}

test('a build request carries each map role at each tier', function () {
    $variant = Variant::factory()->create();
    approvedCanonical($variant, '4k', 4096);
    approvedCanonical($variant, '2k', 2048);

    $request = app(AssembleBuildRequest::class)->handle($variant);

    expect(array_keys($request->channels))->toContain('base_color', 'normal')
        ->and(array_keys($request->channels['base_color']))->toEqualCanonicalizing(['4k', '2k'])
        ->and($request->tiers())->toBe(['2k', '4k']);
});

test('colour space and normal convention are stated, never inferred', function () {
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $request = app(AssembleBuildRequest::class)->handle($variant);
    $manifest = $request->toArray();

    expect($manifest['channels']['base_color']['4k']['colour_space'])->toBe('srgb')
        // A normal map is raw, and which convention it uses cannot be read off
        // the pixels — so it travels with the file or not at all.
        ->and($manifest['channels']['normal']['4k']['normal_convention'])->toBe('opengl')
        ->and($manifest['channels']['base_color']['4k']['normal_convention'])->toBeNull();
});

test('the digest is stable across runs and moves when the inputs do', function () {
    $variant = Variant::factory()->create();
    approvedCanonical($variant, '4k', 4096);

    $assemble = app(AssembleBuildRequest::class);
    $before = $assemble->handle($variant)->digest();

    expect($assemble->handle($variant)->digest())->toBe($before);

    approvedCanonical($variant, '2k', 2048);

    expect($assemble->handle($variant)->digest())->not->toBe($before);
});

test('a variant with nothing canonical is not packaged', function () {
    $calls = [];
    $variant = Variant::factory()->create();

    $package = (new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls)))->handle($variant);

    expect($package)->toBeNull()
        ->and($calls)->toBeEmpty();
});

test('packaging stores the file and records the build', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $package = (new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls)))->handle($variant);

    expect($package)->not->toBeNull()
        ->and($package->revision)->toBe(1)
        ->and($package->builder)->toBe('fake')
        ->and($package->builder_version)->toBe('1.2.3')
        ->and($package->tiers)->toBe(['4k']);

    Storage::disk(config('opal.packages_disk'))->assertExists($package->object_key);
});

test('an unchanged variant is not rebuilt', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $action = new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls));
    $first = $action->handle($variant);
    $again = $action->handle($variant);

    expect($calls)->toHaveCount(1)
        ->and($again->id)->toBe($first->id);
});

test('force rebuilds and takes the next revision rather than overwriting', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $action = new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls));
    $first = $action->handle($variant);
    $second = $action->handle($variant, force: true);

    expect($calls)->toHaveCount(2)
        ->and($second->revision)->toBe(2)
        ->and($second->object_key)->not->toBe($first->object_key);

    // The earlier package is still there: a rebuild adds a revision, it does
    // not replace what someone may already be referencing.
    Storage::disk(config('opal.packages_disk'))->assertExists($first->object_key);
});

test('a changed variant is repackaged without being forced', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant, '4k', 4096);

    $action = new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls));
    $action->handle($variant);

    approvedCanonical($variant, '2k', 2048);
    $second = $action->handle($variant);

    expect($calls)->toHaveCount(2)
        ->and($second->revision)->toBe(2);
});

test('losses reported by the builder are kept on the package', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $losses = [['parameter' => 'subsurface_weight', 'kind' => 'dropped', 'detail' => 'no glTF equivalent']];
    $package = (new PackageVariant(app(AssembleBuildRequest::class), fakeBuilder($calls, $losses)))->handle($variant);

    expect($package->losses)->toBe($losses)
        ->and($package->isLossless())->toBeFalse();
});

test('without a toolbox the pipeline refuses clearly instead of appearing to work', function () {
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    $action = new PackageVariant(app(AssembleBuildRequest::class), new PendingToolbox);

    expect(fn () => $action->handle($variant))
        ->toThrow(RuntimeException::class, 'No USD toolbox is available');
});

test('an odd supplier resolution lands on the rung below it, never above', function () {
    $variant = Variant::factory()->create();
    // The corpus really does hold sizes like this — the legacy import made a
    // tier per resolution it found rather than assuming a ladder. What decides
    // the rung is the file's own pixels, not the tier it was filed under.
    approvedCanonical($variant, '2k', 2401);

    $request = app(AssembleBuildRequest::class)->handle($variant);

    expect($request->tiers())->toBe(['2k'])
        // Asking for 4k would make the toolbox fail rather than upscale, which
        // is the behaviour we want — so we must not ask.
        ->and($request->requiredTiers)->toBe(['preview', '1k', '2k']);
});

test('the larger source wins when two land on the same rung', function () {
    $variant = Variant::factory()->create();
    approvedCanonical($variant, '2k', 2401);
    approvedCanonical($variant, '4k', 2560);

    $request = app(AssembleBuildRequest::class)->handle($variant);
    $manifest = $request->toArray();

    expect($request->tiers())->toBe(['2k'])
        ->and($manifest['channels']['base_color']['2k']['width_px'])->toBe(2560);
});

test('an unmeasured file falls back to the size its tier claims', function () {
    $variant = Variant::factory()->create();
    $representation = app(CreateRepresentation::class)->handle($variant, 'pbr', '4k', [
        'base_color' => File::factory()->create(['colour_space' => 'srgb', 'width_px' => null]),
    ]);
    app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved, User::factory()->create());

    // A claim rather than a measurement, and deliberately trusted: if it turns
    // out to be wrong the toolbox fails on the missing pixels rather than
    // inventing them, which is a better failure than silently shipping a
    // material at a quarter of the resolution it says it is.
    expect(app(AssembleBuildRequest::class)->handle($variant)->tiers())->toBe(['4k']);
});

test('a forced rebuild that produces identical bytes reuses the package', function () {
    $calls = [];
    $variant = Variant::factory()->create();
    approvedCanonical($variant);

    // A deterministic builder: the same inputs give the same digest, as the
    // real toolbox does.
    $builder = new class implements PackageBuilder
    {
        public function name(): string
        {
            return 'deterministic';
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function available(): bool
        {
            return true;
        }

        public function build(BuildRequest $request): BuiltPackage
        {
            $path = tempnam(sys_get_temp_dir(), 'usdz');
            file_put_contents($path, 'same-every-time');

            return new BuiltPackage($path, hash('sha256', $request->digest()), 15, $request->tiers(), [], $this->name(), $this->version());
        }
    };

    $action = new PackageVariant(app(AssembleBuildRequest::class), $builder);
    $first = $action->handle($variant);
    $second = $action->handle($variant, force: true);

    expect($second->id)->toBe($first->id)
        ->and($second->revision)->toBe(1);
});
