<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Jobs\RenderPreview;
use App\Library\Quality\LibraryQuality;
use App\Models\Category;
use App\Models\File;
use App\Models\Material;
use App\Models\QualityTier;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->editor = User::factory()->withTenant($tenant, Role::Editor)->create();
    $this->carpet = Category::query()->where('code', 'CPT')->sole();

    $this->material = function (string $name, array $roles = [], string $tier = '4k', array $targets = ['pbr']): Material {
        $material = Material::factory()->create(['name' => $name, 'category_id' => $this->carpet, 'supplier_id' => Supplier::factory()->create()]);
        $variant = app(AddVariant::class)->handle($material, ['colourway' => ['value' => 'One']]);

        foreach ($targets as $target) {
            $files = [];
            foreach ($roles as $role) {
                $files[$role] = File::factory()->create();
            }

            if ($files !== []) {
                app(ReviewRepresentation::class)->handle(
                    app(CreateRepresentation::class)->handle($variant, $target, $tier, $files),
                    ReviewState::Approved,
                );
            }
        }

        return $material;
    };
});

test('a material with everything has no gaps, a bare one has them all', function () {
    $full = ($this->material)('Full', ['base_color', 'normal', 'roughness', 'ao'], '4k', ['pbr', 'revit', 'preview']);
    $bare = ($this->material)('Bare');

    $quality = app(LibraryQuality::class);
    $rows = $quality->materials($this->editor, [], '', 'name')->keyBy('id');

    expect($rows[$full->id]->getAttribute('gaps'))->toBe(['unpublished'])
        ->and($rows[$bare->id]->getAttribute('gaps'))->toBe(['no-canonical', 'no-revit', 'no-preview', 'unpublished']);
});

test('the summary counts each gap across the library', function () {
    ($this->material)('Full', ['base_color', 'normal', 'roughness', 'ao'], '4k', ['pbr', 'revit', 'preview']);
    ($this->material)('Flat', ['base_color'], '4k');
    // The legacy import lands on odd sizes; anything under 1k counts as small.
    ($this->material)('Small', ['base_color', 'normal', 'roughness', 'ao'], QualityTier::forPixels(960)->slug);

    $summary = app(LibraryQuality::class)->summary($this->editor);

    expect($summary['total'])->toBe(3)
        ->and($summary['with_files'])->toBe(3)
        ->and($summary['gaps']['no-normal'])->toBe(1)
        ->and($summary['gaps']['no-roughness'])->toBe(1)
        ->and($summary['gaps']['no-ao'])->toBe(1)
        ->and($summary['gaps']['low-resolution'])->toBe(1)
        ->and($summary['gaps']['no-revit'])->toBe(2)
        ->and($summary['gaps']['no-canonical'])->toBe(0)
        ->and($summary['gaps']['unpublished'])->toBe(3);
});

test('the page lists the library by gap and filters on the tiles', function () {
    ($this->material)('Full', ['base_color', 'normal', 'roughness', 'ao'], '4k', ['pbr', 'revit', 'preview']);
    ($this->material)('Flat', ['base_color'], '4k');

    Livewire::actingAs($this->editor)
        ->test('pages::quality.index')
        ->assertSee('Full')
        ->assertSee('Flat')
        ->call('toggleGap', 'no-normal')
        ->assertSet('gaps', ['no-normal'])
        ->assertSee('Flat')
        ->assertDontSee('Full')
        ->call('clearGaps')
        ->assertSee('Full');
});

test('the page queues preview renders for a material that has none', function () {
    Queue::fake();
    $material = ($this->material)('Flat', ['base_color'], '4k');

    Livewire::actingAs($this->editor)
        ->test('pages::quality.index')
        ->call('renderPreviews', $material->id);

    Queue::assertPushed(RenderPreview::class);
});

test('a viewer cannot queue work from the quality page', function () {
    $tenant = Tenant::query()->firstOrFail();
    $viewer = User::factory()->withTenant($tenant, Role::Viewer)->create();
    $material = ($this->material)('Flat', ['base_color'], '4k');

    Livewire::actingAs($viewer)
        ->test('pages::quality.index')
        ->call('renderPreviews', $material->id)
        ->assertForbidden();
});

test('coverage counts each map across materials that have a canonical set', function () {
    ($this->material)('Full', ['base_color', 'normal', 'roughness', 'ao'], '4k');
    ($this->material)('Flat', ['base_color'], '4k');
    ($this->material)('Bare');

    $coverage = app(LibraryQuality::class)->coverage($this->editor);

    expect($coverage['with_canonical'])->toBe(2)
        ->and($coverage['roles']['base_color'])->toBe(2)
        ->and($coverage['roles']['normal'])->toBe(1)
        ->and($coverage['roles']['ao'])->toBe(1)
        ->and($coverage['roles']['metallic'])->toBe(0);
});

test('the resolution distribution bands each material, and the page filters by band', function () {
    ($this->material)('Big', ['base_color'], '4k');
    ($this->material)('Middle', ['base_color'], '2k');
    ($this->material)('Small', ['base_color'], QualityTier::forPixels(960)->slug);
    ($this->material)('Bare');

    expect(app(LibraryQuality::class)->distribution($this->editor))
        ->toBe(['none' => 1, 'under-1k' => 1, '1k' => 0, '2k' => 1, '4k' => 1]);

    Livewire::actingAs($this->editor)
        ->test('pages::quality.index')
        ->call('setBand', '4k')
        ->assertSee('Big')
        ->assertDontSee('Middle')
        ->call('setBand', '4k')
        ->assertSet('band', '')
        ->assertSee('Middle');
});

test('a material with no files says so, and names what the legacy library holds', function () {
    $waiting = ($this->material)('Waiting');
    $waiting->forceFill(['legacy_files_expected' => 589])->save();
    // Its only files sit on the Revit target, so nothing can be derived.
    ($this->material)('Referenced', ['base_color'], '4k', ['revit']);

    Livewire::actingAs($this->editor)
        ->test('pages::quality.index')
        ->assertSee('589 in the legacy library')
        ->assertSee('Files, none canonical');
});
