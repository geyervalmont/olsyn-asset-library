<?php

use App\Models\Definition;
use App\Models\Package;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
});

test('a definition describes a variant that is generated rather than scanned', function () {
    $variant = Variant::factory()->create();

    $definition = $variant->definition()->create([
        'generator' => 'paint',
        'generator_version' => '1.0.0',
        'parameters' => ['l' => 94.2, 'a' => -0.4, 'b' => 2.1, 'sheen' => 'low'],
    ]);

    expect($variant->refresh()->definition->generator)->toBe('paint')
        ->and($definition->parameters['sheen'])->toBe('low');
});

test('a definition is stale until it is baked', function () {
    $definition = Definition::factory()->for(Variant::factory())->create([
        'generator' => 'paint',
        'parameters' => ['l' => 94.2],
        'baked_at' => null,
    ]);

    expect($definition->isStale())->toBeTrue();

    $definition->markBaked();

    expect($definition->refresh()->isStale())->toBeFalse();
});

test('changing the parameters makes a baked definition stale again', function () {
    $definition = Definition::factory()->for(Variant::factory())->create([
        'generator' => 'paint',
        'parameters' => ['l' => 94.2],
    ]);
    $definition->markBaked();

    $definition->update(['parameters' => ['l' => 71.8]]);

    expect($definition->refresh()->isStale())->toBeTrue();
});

test('the digest ignores key order so a round trip does not look like a change', function () {
    $variant = Variant::factory()->create();

    $one = Definition::factory()->for($variant)->create([
        'generator' => 'paint',
        'parameters' => ['l' => 94.2, 'a' => -0.4, 'b' => 2.1],
    ]);
    $two = Definition::factory()->for(Variant::factory())->create([
        'generator' => 'paint',
        'parameters' => ['b' => 2.1, 'l' => 94.2, 'a' => -0.4],
    ]);

    expect($one->digest())->toBe($two->digest());
});

test('a variant carries one editable procedural definition', function () {
    $variant = Variant::factory()->create();
    $variant->definition()->create(['generator' => 'paint', 'parameters' => []]);

    expect(fn () => $variant->definition()->create(['generator' => 'masonry', 'parameters' => []]))
        ->toThrow(QueryException::class);
});

test('packages are listed newest revision first', function () {
    $variant = Variant::factory()->create();

    foreach ([1, 3, 2] as $revision) {
        Package::factory()->for($variant)->create(['revision' => $revision]);
    }

    expect($variant->packages->pluck('revision')->all())->toBe([3, 2, 1]);
});

test('a revision cannot be rebuilt in place', function () {
    $variant = Variant::factory()->create();
    Package::factory()->for($variant)->create(['revision' => 1]);

    expect(fn () => Package::factory()->for($variant)->create(['revision' => 1]))
        ->toThrow(QueryException::class);
});

test('identical bytes cannot be recorded as two packages', function () {
    $digest = str_repeat('a', 64);
    Package::factory()->for(Variant::factory())->create(['sha256' => $digest]);

    expect(fn () => Package::factory()->for(Variant::factory())->create(['sha256' => $digest]))
        ->toThrow(QueryException::class);
});

test('a package reports which resolutions it holds', function () {
    $package = Package::factory()->for(Variant::factory())->create([
        'tiers' => ['preview', '1k', '2k'],
    ]);

    expect($package->hasTier('2k'))->toBeTrue()
        ->and($package->hasTier('4k'))->toBeFalse();
});

test('losses are recorded rather than prevented, and absence is not the same as none', function () {
    $assessed = Package::factory()->for(Variant::factory())->create(['losses' => []]);
    $lossy = Package::factory()->for(Variant::factory())->create([
        'losses' => [['parameter' => 'subsurface_weight', 'kind' => 'dropped']],
    ]);
    $unassessed = Package::factory()->for(Variant::factory())->create(['losses' => null]);

    expect($assessed->isLossless())->toBeTrue()
        ->and($lossy->isLossless())->toBeFalse()
        // Null means the build predates loss reporting, which is not a claim
        // that nothing was lost.
        ->and($unassessed->isLossless())->toBeFalse();
});
