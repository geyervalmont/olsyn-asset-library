<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Enums\VersionStatus;
use App\Models\File;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);

    $this->material = Material::factory()->create();
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => 'Ashen']);
    $this->slate = app(AddVariant::class)->handle($this->material, ['colourway' => 'Slate']);
    $this->create = app(CreateRepresentation::class);
    $this->review = app(ReviewRepresentation::class);
    $this->approve = fn ($representation) => $this->review->handle($representation, ReviewState::Approved);
});

test('a version pins only current canonical packages and publishing moves the pointer', function () {
    $publisher = User::factory()->create();
    ($this->approve)($this->create->handle($this->ashen, 'pbr', '4k', ['base_color' => File::factory()->create()]));
    $slateCandidate = $this->create->handle($this->slate, 'pbr', '4k', ['base_color' => File::factory()->create()]);
    $ashenPackage = publishablePackage($this->ashen, '4k');

    $v1 = app(CutVersion::class)->handle($this->material, $publisher, 'first cut');

    expect($v1->number)->toBe(1)
        ->and($v1->status)->toBe(VersionStatus::Draft)
        ->and($v1->packages()->count())->toBe(1)
        ->and($v1->packageFor($this->ashen)?->is($ashenPackage))->toBeTrue()
        ->and($v1->packageFor($this->slate))->toBeNull()
        ->and($slateCandidate->review_state)->toBe(ReviewState::Candidate)
        ->and($this->material->fresh()?->current_version_id)->toBeNull();

    app(PublishVersion::class)->handle($v1, $publisher);

    expect($v1->fresh()?->status)->toBe(VersionStatus::Published)
        ->and($v1->fresh()?->published_at)->not->toBeNull()
        ->and($this->material->fresh()?->currentVersion?->is($v1))->toBeTrue();
});

test('a new version can re-reference an unchanged package and rollback is a pointer move', function () {
    ($this->approve)($this->create->handle($this->ashen, 'pbr', '4k', ['base_color' => File::factory()->create()]));
    $package = publishablePackage($this->ashen, '4k');
    $v1 = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $v2 = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    expect($v2->number)->toBe(2)
        ->and($v1->fresh()?->status)->toBe(VersionStatus::Superseded)
        ->and($v2->packageFor($this->ashen)?->is($package))->toBeTrue()
        ->and($v1->packageFor($this->ashen)?->is($package))->toBeTrue()
        ->and($package->versions()->count())->toBe(2)
        ->and($this->material->fresh()?->currentVersion?->is($v2))->toBeTrue();

    app(PublishVersion::class)->handle($v1);

    expect($this->material->fresh()?->currentVersion?->is($v1))->toBeTrue()
        ->and($v1->fresh()?->status)->toBe(VersionStatus::Published)
        ->and($v2->fresh()?->status)->toBe(VersionStatus::Superseded)
        ->and($v1->fresh()?->isCurrent())->toBeTrue();
});

test('a material with nothing approved cannot be versioned', function () {
    $this->create->handle($this->ashen, 'pbr', '4k', ['base_color' => File::factory()->create()]);

    expect(fn () => app(CutVersion::class)->handle($this->material))->toThrow(LogicException::class);
});

test('a stale package cannot be published after the canonical material changes', function () {
    ($this->approve)($this->create->handle($this->ashen, 'pbr', '1k', ['base_color' => File::factory()->create()]));
    $stale = publishablePackage($this->ashen, '1k');
    ($this->approve)($this->create->handle($this->ashen, 'pbr', '1k', [
        'base_color' => File::factory()->create(),
        'normal' => File::factory()->create(),
        'roughness' => File::factory()->create(),
    ]));

    expect(fn () => app(CutVersion::class)->handle($this->material))
        ->toThrow(LogicException::class, 'no up-to-date canonical USDZ package');

    $current = publishablePackage($this->ashen, '1k');
    $version = app(CutVersion::class)->handle($this->material);

    expect($version->packageFor($this->ashen)?->is($current))->toBeTrue()
        ->and($version->packageFor($this->ashen)?->is($stale))->toBeFalse();
});

test('publishing rechecks that each package has its required cache', function () {
    ($this->approve)($this->create->handle($this->ashen, 'pbr', '1k', ['base_color' => File::factory()->create()]));
    $package = publishablePackage($this->ashen, '1k');
    $version = app(CutVersion::class)->handle($this->material);
    $package->derivatives()->delete();

    expect(fn () => app(PublishVersion::class)->handle($version))
        ->toThrow(LogicException::class, 'has no ready [revit] projection');
});
