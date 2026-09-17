<?php

use App\Actions\Packaging\AssembleBuildRequest;
use App\Models\File;
use App\Models\MapRole;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\QualityTier;
use App\Models\Target;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/legacy.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create the immutable package boundary and a ready consumer cache for tests
 * whose subject starts after the toolbox has completed its work.
 *
 * @param  array<string, File>|null  $assets
 */
function publishablePackage(Variant $variant, string $quality = '2k', string $target = 'revit', ?array $assets = null): Package
{
    $request = app(AssembleBuildRequest::class)->handle($variant);
    $package = Package::factory()->for($variant)->create([
        'revision' => (int) $variant->packages()->max('revision') + 1,
        'request_digest' => $request->digest(),
        'tiers' => [$quality],
    ]);
    $targetModel = Target::fromSlug($target);
    $qualityModel = QualityTier::fromSlug($quality);
    $derivative = PackageDerivative::factory()->for($package)->create([
        'target_id' => $targetModel->getKey(),
        'quality_tier_id' => $qualityModel->getKey(),
        'source_sha256' => $package->sha256,
        'converter' => 'usd-toolbox:'.$target,
    ]);

    if ($assets === null) {
        $canonical = $variant->representations()
            ->approved()
            ->where('target_id', Target::canonical()?->getKey())
            ->with(['quality', 'representationFiles.file', 'representationFiles.role'])
            ->get()
            ->sortByDesc(fn ($representation): int => $representation->quality->pixels ?? 0)
            ->first();
        $source = $canonical?->filesByRole() ?? [];
        $assets = array_filter([
            'base_color' => $source['base_color'] ?? null,
            'bump' => $source['bump'] ?? $source['normal'] ?? $source['height'] ?? null,
            'glossiness' => $source['glossiness'] ?? $source['roughness'] ?? null,
        ]);
    }

    foreach ($assets as $role => $file) {
        $mapRole = MapRole::fromSlug($role);
        $derivative->derivativeFiles()->create([
            'file_id' => $file->getKey(),
            'map_role_id' => $mapRole->getKey(),
            'colour_space' => $file->colour_space ?? $mapRole->colour_space,
        ]);
    }

    return $package->refresh();
}
