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
use App\Models\QualityTier;
use App\Models\Target;
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

test('a version snapshots only approved representations and publishing moves the current pointer', function () {
    $publisher = User::factory()->create();
    $ashenPbr = ($this->approve)($this->create->handle($this->ashen, 'pbr', '4k', ['base_color' => File::factory()->create()]));
    $ashenRevit = ($this->approve)($this->create->handle($this->ashen, 'revit', '2k', ['base_color' => File::factory()->create()]));
    $slateCandidate = $this->create->handle($this->slate, 'pbr', '4k', ['base_color' => File::factory()->create()]);

    $v1 = app(CutVersion::class)->handle($this->material, $publisher, 'first cut');

    expect($v1->number)->toBe(1)
        ->and($v1->status)->toBe(VersionStatus::Draft)
        ->and($v1->representations()->count())->toBe(2)
        ->and($v1->representations->contains($slateCandidate))->toBeFalse()
        ->and($this->material->fresh()?->current_version_id)->toBeNull();

    app(PublishVersion::class)->handle($v1, $publisher);

    expect($v1->fresh()?->status)->toBe(VersionStatus::Published)
        ->and($v1->fresh()?->published_at)->not->toBeNull()
        ->and($this->material->fresh()?->currentVersion?->is($v1))->toBeTrue()
        ->and($v1->representationFor($this->ashen, Target::fromSlug('revit'), QualityTier::fromSlug('2k'))?->is($ashenRevit))->toBeTrue()
        ->and($v1->representationFor($this->slate, Target::fromSlug('pbr'), QualityTier::fromSlug('4k')))->toBeNull();
});

test('a new version re-references unchanged representations and rollback is a pointer move', function () {
    $ashenPbr = ($this->approve)($this->create->handle($this->ashen, 'pbr', '4k', ['base_color' => File::factory()->create()]));
    $ashenRevitV1 = ($this->approve)($this->create->handle($this->ashen, 'revit', '2k', ['base_color' => File::factory()->create()]));
    $v1 = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    $ashenRevitV2 = ($this->approve)($this->create->handle($this->ashen, 'revit', '2k', ['base_color' => File::factory()->create()]));
    $v2 = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    $revit = Target::fromSlug('revit');
    $pbr = Target::fromSlug('pbr');
    $q2k = QualityTier::fromSlug('2k');
    $q4k = QualityTier::fromSlug('4k');

    expect($v2->number)->toBe(2)
        ->and($v1->fresh()?->status)->toBe(VersionStatus::Superseded)
        ->and($v2->representationFor($this->ashen, $pbr, $q4k)?->is($ashenPbr))->toBeTrue()
        ->and($v2->representationFor($this->ashen, $revit, $q2k)?->is($ashenRevitV2))->toBeTrue()
        ->and($v1->representationFor($this->ashen, $revit, $q2k)?->is($ashenRevitV1))->toBeTrue()
        ->and($ashenRevitV1->fresh()?->review_state)->toBe(ReviewState::Superseded)
        ->and($ashenPbr->versions()->count())->toBe(2)
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

test('cutting a version keeps one representation per key, the fuller and newer one', function () {
    $thin = ($this->approve)($this->create->handle($this->ashen, 'pbr', '1k', ['base_color' => File::factory()->create()]));
    $full = ($this->approve)($this->create->handle($this->ashen, 'pbr', '1k', ['base_color' => File::factory()->create(), 'normal' => File::factory()->create(), 'roughness' => File::factory()->create()]));
    // An importer can leave two approved at one key; force that state.
    $thin->update(['review_state' => ReviewState::Approved]);

    $version = app(CutVersion::class)->handle($this->material);

    expect($version->representations()->pluck('representations.id')->all())->toBe([$full->id]);
});
