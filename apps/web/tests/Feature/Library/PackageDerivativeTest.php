<?php

use App\Actions\Packaging\BuildPackageDerivative;
use App\Library\Derivatives\ToolboxPackageDerivativeBuilder;
use App\Library\FileStore;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\QualityTier;
use App\Models\Target;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

/** @param array<string, string> $assets */
function derivativeExportZip(string $variantCode, array $assets): string
{
    $path = tempnam(sys_get_temp_dir(), 'revit-export').'.zip';
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $slots = [];

    foreach ($assets as $role => $bytes) {
        $entry = 'materials/'.strtolower($variantCode).'/'.$role.'.png';
        $archive->addFromString($entry, $bytes);
        $slots[$role] = [
            'path' => $entry,
            'sha256' => hash('sha256', $bytes),
            'media_type' => 'image/png',
        ];
    }

    $archive->addFromString('revit-material.json', json_encode([
        'schema' => 'usd-toolbox.revit-material.v1',
        'materials' => [['id' => $variantCode, 'slots' => $slots]],
    ], JSON_THROW_ON_ERROR));
    $archive->close();

    return $path;
}

function derivativeToolbox(string $zip): string
{
    $script = tempnam(sys_get_temp_dir(), 'derivative-toolbox').'.sh';
    file_put_contents($script, <<<SH
#!/bin/sh
if [ "\$1" = "--version" ]; then
  echo "usd-toolbox 0.4.2"
  exit 0
fi
output=""
report=""
while [ "\$#" -gt 0 ]; do
  case "\$1" in
    --output) output="\$2"; shift 2 ;;
    --report) report="\$2"; shift 2 ;;
    *) shift ;;
  esac
done
cp "{$zip}" "\$output"
printf '%s' '{"losses":[{"code":"roughness_inverted"}]}' > "\$report"
SH);
    chmod($script, 0755);

    return $script;
}

beforeEach(function () {
    Storage::fake('local');
    config()->set('opal.files_disk', 'local');
    config()->set('opal.packages_disk', 'local');
    $this->seed(LibrarySeeder::class);
    $this->variant = Variant::factory()->create();
    $this->packageBytes = 'a deterministic canonical usdz fixture';
    $this->package = Package::factory()->for($this->variant)->create([
        'object_key' => 'opal/packages/'.$this->variant->code.'/r1.usdz',
        'sha256' => hash('sha256', $this->packageBytes),
        'bytes' => strlen($this->packageBytes),
        'tiers' => ['2k'],
    ]);
    Storage::disk('local')->put($this->package->object_key, $this->packageBytes);
    $this->zip = derivativeExportZip($this->variant->code, [
        'base_color' => 'base-colour-png',
        'bump' => 'normal-as-bump-png',
        'glossiness' => 'inverted-roughness-png',
    ]);
    $this->binary = derivativeToolbox($this->zip);
});

afterEach(function () {
    @unlink($this->binary);
    @unlink($this->zip);
});

test('a verified canonical package becomes a keyed Revit cache', function () {
    $builder = new ToolboxPackageDerivativeBuilder($this->binary, 'local');
    $action = new BuildPackageDerivative($builder, app(FileStore::class));

    $derivative = $action->handle($this->package, 'revit', '2k');
    $again = $action->handle($this->package, 'revit', '2k');

    expect($derivative->is($again))->toBeTrue()
        ->and(PackageDerivative::count())->toBe(1)
        ->and($derivative->source_sha256)->toBe($this->package->sha256)
        ->and($derivative->converter)->toBe('usd-toolbox:revit')
        ->and($derivative->converter_version)->toBe('0.4.2')
        ->and($derivative->target->is(Target::fromSlug('revit')))->toBeTrue()
        ->and($derivative->quality->is(QualityTier::fromSlug('2k')))->toBeTrue()
        ->and(array_keys($derivative->filesByRole()))->toBe(['base_color', 'bump', 'glossiness'])
        ->and($derivative->losses)->toBe([['code' => 'roughness_inverted']]);

    foreach ($derivative->files as $file) {
        Storage::disk('local')->assertExists($file->object_key);
    }
});

test('a package whose stored bytes do not match its identity is refused', function () {
    Storage::disk('local')->put($this->package->object_key, 'tampered');
    $action = new BuildPackageDerivative(
        new ToolboxPackageDerivativeBuilder($this->binary, 'local'),
        app(FileStore::class),
    );

    expect(fn () => $action->handle($this->package, 'revit', '2k'))
        ->toThrow(RuntimeException::class, 'does not match its recorded hash and size');

    expect(PackageDerivative::count())->toBe(0);
});
