<?php

use App\Library\Packaging\BuildRequest;
use App\Library\Packaging\ChannelSource;
use App\Library\Packaging\ToolboxPackageBuilder;
use Illuminate\Support\Facades\Storage;

/**
 * A stand-in for the toolbox binary: answers --version, and on build copies a
 * package the test prepared. Exercising the real builder against a real process
 * is the only way to cover argument handling, staging and the archive checks.
 */
function stubToolbox(string $versionLine, string $packageToEmit): string
{
    $script = tempnam(sys_get_temp_dir(), 'toolbox').'.sh';

    file_put_contents($script, <<<SH
#!/bin/sh
if [ "\$1" = "--version" ]; then
  echo "{$versionLine}"
  exit 0
fi
# build --manifest <m> --output <o> --report <r>
cp "{$packageToEmit}" "\$5"
printf '{"tiers":["1k"],"losses":[],"version":"9.9.9"}' > "\$7"
exit 0
SH);
    chmod($script, 0755);

    return $script;
}

/**
 * @param  array<string, int>  $entries  name => byte size
 */
function makePackage(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'pkg').'.usdz';
    $zip = new ZipArchive;

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("could not create the fixture package at {$path}");
    }

    foreach ($entries as $name => $size) {
        $zip->addFromString($name, str_repeat('x', $size));
    }

    $zip->close();

    return $path;
}

function requestFor(string $code = 'TEST-VARIANT'): BuildRequest
{
    return new BuildRequest(
        variantCode: $code,
        name: 'Test',
        channels: ['base_color' => ['1k' => new ChannelSource('abc123', 'opal/files/ab/c1/abc123.png', 10, 'srgb')]],
    );
}

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('opal/files/ab/c1/abc123.png', 'not-really-an-image');
});

test('the version drops the program name the binary prints alongside it', function () {
    $builder = new ToolboxPackageBuilder(
        stubToolbox('usd-toolbox 0.1.0', makePackage(['material.usda' => 100])),
        'local',
    );

    // Recording the whole line would leave every package saying
    // "usd-toolbox usd-toolbox 0.1.0", since the name is stored beside it.
    expect($builder->version())->toBe('0.1.0');
});

test('a version the binary prints unusually is kept rather than mangled', function () {
    $builder = new ToolboxPackageBuilder(
        stubToolbox('built from source', makePackage(['material.usda' => 100])),
        'local',
    );

    expect($builder->version())->toBe('built from source');
});

test('a package builds and reports what the toolbox found', function () {
    $builder = new ToolboxPackageBuilder(
        stubToolbox('usd-toolbox 0.1.0', makePackage(['material.usda' => 2048, 'textures/a.png' => 5000])),
        'local',
    );

    $built = $builder->build(requestFor());

    expect($built->tiers)->toBe(['1k'])
        ->and($built->builderVersion)->toBe('9.9.9')
        ->and($built->bytes)->toBeGreaterThan(0)
        ->and(is_file($built->path))->toBeTrue();
});

test('a package whose scene description swallowed its textures is refused', function () {
    // The shape of the real defect: provenance serialised raw image bytes into
    // the USDA as JSON integers, quadrupling every package.
    $builder = new ToolboxPackageBuilder(
        stubToolbox('usd-toolbox 0.1.0', makePackage([
            'material.usda' => 12 * 1024 * 1024,
            'textures/a.png' => 5000,
        ])),
        'local',
    );

    expect(fn () => $builder->build(requestFor('BLOATED')))
        ->toThrow(RuntimeException::class, 'scene description');
});

test('large images do not trip the guard, only large descriptions', function () {
    // A lossless map set is legitimately big. Checking the description rather
    // than the package total is what keeps this from false-alarming.
    $builder = new ToolboxPackageBuilder(
        stubToolbox('usd-toolbox 0.1.0', makePackage([
            'material.usda' => 4096,
            'textures/a.png' => 40 * 1024 * 1024,
        ])),
        'local',
    );

    expect($builder->build(requestFor())->bytes)->toBeGreaterThan(1000);
});

test('a request with no channels is refused before a process is started', function () {
    $builder = new ToolboxPackageBuilder(
        stubToolbox('usd-toolbox 0.1.0', makePackage(['material.usda' => 100])),
        'local',
    );

    $empty = new BuildRequest(variantCode: 'EMPTY', name: 'Empty', channels: []);

    expect(fn () => $builder->build($empty))
        ->toThrow(RuntimeException::class, 'no canonical files');
});
