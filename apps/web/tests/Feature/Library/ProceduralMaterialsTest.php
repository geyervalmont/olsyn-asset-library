<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Jobs\BakeProceduralMaterial;
use App\Library\Procedural\ProceduralAsset;
use App\Library\Procedural\ProceduralBake;
use App\Library\Procedural\ProceduralBaker;
use App\Library\Procedural\ToolboxProceduralBaker;
use App\Models\Category;
use App\Models\Definition;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

final class FakeProceduralBaker implements ProceduralBaker
{
    public function available(): bool
    {
        return true;
    }

    public function bake(array $definition): ProceduralBake
    {
        $images = [];

        foreach (['base_color' => 120, 'normal' => 190, 'roughness' => 210, 'height' => 90, 'metallic' => 0] as $role => $grey) {
            $image = imagecreatetruecolor((int) $definition['width_px'], (int) $definition['height_px']);
            imagefilledrectangle($image, 0, 0, (int) $definition['width_px'] - 1, (int) $definition['height_px'] - 1, imagecolorallocate($image, $grey, $grey, $grey));
            ob_start();
            imagepng($image);
            $contents = (string) ob_get_clean();
            $images[] = new ProceduralAsset($role, $role.'.png', 'png', 'image/png', hash('sha256', $contents), $contents);
        }

        return new ProceduralBake(
            generator: (string) $definition['generator'],
            generatorVersion: '0.1.1',
            definitionDigest: str_repeat('a', 64),
            widthPx: (int) $definition['width_px'],
            heightPx: (int) $definition['height_px'],
            widthMm: (float) $definition['width_mm'],
            heightMm: (float) $definition['height_mm'],
            assets: $images,
        );
    }
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

test('the studio is contributor-only and stores a versioned recipe', function () {
    Queue::fake();
    $paint = Category::query()->where('code', 'PNT')->sole();

    $this->actingAs($this->viewer)->get(route('materials.studio'))->assertForbidden();
    $this->actingAs($this->editor)->get(route('materials.studio'))->assertOk()->assertSee('Material Studio');

    Livewire::actingAs($this->editor)
        ->test('pages::materials.studio')
        ->set('name', 'Studio paint')
        ->set('category_id', (string) $paint->getKey())
        ->set('colourway', 'Warm white')
        ->set('recipe.colour', '#E4E0D8')
        ->call('save')
        ->assertHasNoErrors();

    $material = Material::query()->where('name', 'Studio paint')->sole();
    $definition = $material->variants()->sole()->definition;

    expect($definition?->generator)->toBe('paint')
        ->and($definition?->parameters['recipe']['colour'])->toBe('#E4E0D8')
        ->and($definition?->isStale())->toBeTrue();

    Queue::assertPushed(BakeProceduralMaterial::class);
});

test('a procedural job creates a traceable candidate without approving it', function () {
    app()->instance(ProceduralBaker::class, new FakeProceduralBaker);
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Clay']);
    $definition = Definition::factory()->create(['variant_id' => $variant]);

    $run = BakeProceduralMaterial::launchHere(['definition_id' => $definition->getKey()], $variant, $this->editor);
    $representation = $variant->representations()->with('provenanceEvents')->sole();

    expect($run->fresh()?->result['representation_id'])->toBe($representation->getKey())
        ->and($representation->review_state)->toBe(ReviewState::Candidate)
        ->and(array_keys($representation->filesByRole()))->toBe(['base_color', 'normal', 'roughness', 'metallic', 'height'])
        ->and($representation->metadata['procedural']['definition_id'])->toBe($definition->getKey())
        ->and($representation->provenanceEvents()->sole()->action)->toBe('generated')
        ->and($definition->fresh()?->isStale())->toBeFalse();
});

test('an existing material can receive an uploaded improvement', function () {
    $material = Material::factory()->create(['name' => 'Existing stone']);
    $variant = app(AddVariant::class)->handle($material, ['finish' => 'Honed']);
    $image = imagecreatetruecolor(32, 32);
    ob_start();
    imagepng($image);
    $upload = UploadedFile::fake()->createWithContent('normal.png', (string) ob_get_clean());

    Livewire::actingAs($this->editor)
        ->test('pages::materials.create', ['mode' => 'existing', 'materialCode' => $material->code, 'variantCode' => $variant->code])
        ->set('maps.normal', $upload)
        ->set('normal_convention', 'directx')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('materials.show', $material));

    $representation = $variant->representations()->sole();
    expect($representation->metadata['normal_convention'])->toBe('directx')
        ->and($representation->review_state)->toBe(ReviewState::Candidate)
        ->and($variant->provenanceEvents()->sole()->action)->toBe('uploaded');
});

test('texture set filenames are assigned to map roles without inferring interpretation', function () {
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    $base = UploadedFile::fake()->createWithContent('brick_base-color_2k.png', $bytes);
    $normal = UploadedFile::fake()->createWithContent('brick_normal_dx_2k.png', $bytes);

    $component = Livewire::actingAs($this->editor)
        ->test('pages::materials.create')
        ->set('auto_maps', [$base, $normal])
        ->assertHasNoErrors();

    expect(array_keys($component->get('maps')))->toBe(['base_color', 'normal'])
        ->and($component->get('normal_convention'))->toBe('opengl');
});

test('material import refuses to create an empty catalog record', function () {
    $paint = Category::query()->where('code', 'PNT')->sole();

    Livewire::actingAs($this->editor)
        ->test('pages::materials.create')
        ->set('name', 'Empty material')
        ->set('category_id', (string) $paint->getKey())
        ->call('save')
        ->assertHasErrors(['maps']);

    expect(Material::query()->where('name', 'Empty material')->exists())->toBeFalse();
});

test('studio rejects cropped pattern dimensions and suggests a complete repeat', function () {
    Queue::fake();
    $masonry = Category::query()->where('code', 'CER')->sole();

    Livewire::actingAs($this->editor)
        ->test('pages::materials.studio')
        ->set('name', 'Cropped running bond')
        ->set('category_id', (string) $masonry->getKey())
        ->call('chooseGenerator', 'masonry')
        ->set('width_mm', 500)
        ->call('save')
        ->assertHasErrors(['width_mm']);

    expect(Material::query()->where('name', 'Cropped running bond')->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('definition digests are stable across nested object key order', function () {
    $definition = Definition::factory()->make(['parameters' => ['recipe' => ['roughness' => 0.5, 'nested' => ['b' => 2, 'a' => 1]], 'seed' => 3]]);
    $first = $definition->digest();
    $definition->parameters = ['seed' => 3, 'recipe' => ['nested' => ['a' => 1, 'b' => 2], 'roughness' => 0.5]];

    expect($definition->digest())->toBe($first);
});

test('the toolbox adapter rejects bytes that do not match its report', function () {
    $script = tempnam(sys_get_temp_dir(), 'procedural').'.sh';
    $contents = <<<'SH'
#!/bin/sh
if [ "$1" = "bake-procedural" ]; then
  shift
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --output-dir) out="$2"; shift 2 ;;
      --report) report="$2"; shift 2 ;;
      *) shift ;;
    esac
  done
  printf 'not-really-a-png' > "$out/base_color.png"
  printf '%s' '{"schema":"usd-toolbox.procedural-bake.v1","generator":"paint","generator_version":"0.1.1","definition_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","width_px":8,"height_px":8,"width_mm":100,"height_mm":100,"assets":[{"role":"base_color","path":"base_color.png","extension":"png","media_type":"image/png","sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","bytes":16}]}' > "$report"
fi
SH;
    file_put_contents($script, $contents);
    chmod($script, 0755);

    expect(fn () => (new ToolboxProceduralBaker($script))->bake([
        'schema' => 1, 'width_px' => 8, 'height_px' => 8, 'width_mm' => 100, 'height_mm' => 100,
        'seed' => 1, 'generator' => 'paint', 'parameters' => [],
    ]))->toThrow(RuntimeException::class, 'does not match its report');

    unlink($script);
});
