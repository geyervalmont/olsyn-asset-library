<?php

use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Models\File;
use App\Models\MapRole;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
});

test('a representation is a variant at a target and quality with files by map role', function () {
    $variant = Variant::factory()->create();
    $baseColor = File::factory()->create();
    $normal = File::factory()->create(['colour_space' => null]);

    $representation = app(CreateRepresentation::class)->handle($variant, 'pbr', '4k', [
        'base_color' => $baseColor,
        'normal' => $normal,
        'roughness' => File::factory()->create(['colour_space' => null]),
    ], metadata: ['normal_convention' => 'opengl']);

    expect($representation->kind)->toBe(Representation::KIND_PBR_SET)
        ->and($representation->review_state)->toBe(ReviewState::Candidate)
        ->and($representation->target->slug)->toBe('pbr')
        ->and($representation->target->is_canonical)->toBeTrue()
        ->and($representation->quality->pixels)->toBe(4096)
        ->and($representation->fileFor('base_color')?->is($baseColor))->toBeTrue()
        ->and($representation->fileFor('height'))->toBeNull()
        ->and(array_keys($representation->filesByRole()))->toBe(['base_color', 'normal', 'roughness'])
        ->and($representation->representationFiles()->where('file_id', $normal->getKey())->sole()->colour_space)->toBe('linear')
        ->and($representation->metadata)->toBe(['normal_convention' => 'opengl']);
});

test('kind is inferred, custom pixel sizes become tiers, and the key is immutable', function () {
    $variant = Variant::factory()->create();
    $create = app(CreateRepresentation::class);

    $photo = $create->handle($variant, 'pbr', 3000, ['base_color' => File::factory()->create()]);
    $mdl = $create->handle($variant, 'omniverse', '2k', ['mdl' => File::factory()->create(['kind' => 'other', 'mime_type' => 'text/plain'])]);

    expect($photo->kind)->toBe(Representation::KIND_IMAGE)
        ->and($photo->quality->slug)->toBe('3000px')
        ->and(QualityTier::forPixels(3000)->is($photo->quality))->toBeTrue()
        ->and($mdl->kind)->toBe(Representation::KIND_PACKAGE);

    $photo->quality_tier_id = QualityTier::fromSlug('1k')->getKey();

    expect(fn () => $photo->save())->toThrow(LogicException::class);
    expect(fn () => $create->handle($variant, 'pbr', '1k', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $create->handle($variant, 'pbr', '1k', ['glitter' => File::factory()->create()]))->toThrow(InvalidArgumentException::class);
});

test('approving supersedes the earlier approved representation for the same key and is recorded', function () {
    $reviewer = User::factory()->create(['name' => 'Harrison']);
    $variant = Variant::factory()->create();
    $create = app(CreateRepresentation::class);
    $review = app(ReviewRepresentation::class);

    $first = $create->handle($variant, 'revit', '2k', ['base_color' => File::factory()->create()]);
    $second = $create->handle($variant, 'revit', '2k', ['base_color' => File::factory()->create()]);
    $other = $create->handle($variant, 'revit', '4k', ['base_color' => File::factory()->create()]);

    $review->handle($first, ReviewState::Approved, $reviewer);
    $review->handle($other, ReviewState::Approved, $reviewer);
    $review->handle($second, ReviewState::Rejected, $reviewer, 'seam visible');
    $review->handle($second, ReviewState::Approved, $reviewer, 'fixed');

    expect($first->fresh()?->review_state)->toBe(ReviewState::Superseded)
        ->and($second->fresh()?->review_state)->toBe(ReviewState::Approved)
        ->and($second->fresh()?->reviewer?->name)->toBe('Harrison')
        ->and($other->fresh()?->review_state)->toBe(ReviewState::Approved)
        ->and(Representation::query()->forKey($variant, Target::fromSlug('revit'), QualityTier::fromSlug('2k'))->approved()->count())->toBe(1)
        ->and($second->provenanceEvents()->pluck('action')->all())->toBe(['rejected', 'approved'])
        ->and($second->provenanceEvents()->first()?->notes)->toBe('seam visible')
        ->and($second->provenanceEvents()->first()?->inputs()->count())->toBe(1);

    expect(fn () => $review->handle($first, ReviewState::Candidate))->toThrow(InvalidArgumentException::class);
});

test('map roles and targets are data', function () {
    MapRole::query()->create(['slug' => 'clearcoat', 'name' => 'Clearcoat', 'colour_space' => 'linear']);
    Target::query()->create(['slug' => 'unreal', 'name' => 'Unreal']);
    $variant = Variant::factory()->create();

    $representation = app(CreateRepresentation::class)->handle($variant, 'unreal', '2k', ['clearcoat' => File::factory()->create()]);

    expect($representation->fileFor('clearcoat'))->not->toBeNull()
        ->and(Target::canonical()?->slug)->toBe('pbr');

    // Last: one file per role is enforced by the database.
    expect(fn () => $representation->representationFiles()->create(['file_id' => File::factory()->create()->getKey(), 'map_role_id' => MapRole::fromSlug('clearcoat')->getKey()]))
        ->toThrow(QueryException::class);
});
