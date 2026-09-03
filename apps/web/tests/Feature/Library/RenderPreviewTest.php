<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\RunStatus;
use App\Jobs\RenderPreview;
use App\Library\FileStore;
use App\Library\Previews\MaterialPreviews;
use App\Library\Previews\SphereRenderer;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\Representation;
use App\Models\User;
use App\Models\WorkerRun;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function mapPng(int $size, int $r, int $g, int $b, bool $checker = false): string
{
    $image = imagecreatetruecolor($size, $size);
    $a = imagecolorallocate($image, $r, $g, $b);
    $c = imagecolorallocate($image, min(255, $r + 80), min(255, $g + 80), min(255, $b + 80));
    imagefilledrectangle($image, 0, 0, $size, $size, $a);

    if ($checker) {
        imagefilledrectangle($image, 0, 0, (int) ($size / 2) - 1, (int) ($size / 2) - 1, $c);
        imagefilledrectangle($image, (int) ($size / 2), (int) ($size / 2), $size, $size, $c);
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function pixelAt(string $png, int $x, int $y): array
{
    $image = imagecreatefromstring($png);
    $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
});

test('the sphere renderer lights a swatch from tiny maps', function () {
    $png = app(SphereRenderer::class)->render([
        'base_color' => mapPng(8, 160, 90, 60, checker: true),
        'normal' => mapPng(8, 128, 128, 255),
        'roughness' => mapPng(8, 60, 60, 60),
    ], 64);

    $info = getimagesizefromstring($png);
    expect($info[0])->toBe(64)->and($info[1])->toBe(64)->and($info['mime'])->toBe('image/png');

    [, , , $cornerAlpha] = pixelAt($png, 0, 0);
    $upperLeft = pixelAt($png, 22, 20);
    $lowerRight = pixelAt($png, 44, 46);
    $centre = pixelAt($png, 32, 32);

    expect($cornerAlpha)->toBe(127)
        ->and($centre[3])->toBe(0)
        ->and(array_sum(array_slice($upperLeft, 0, 3)))->toBeGreaterThan(array_sum(array_slice($lowerRight, 0, 3)))
        ->and($centre[0])->toBeGreaterThan($centre[2]);
});

test('the render job produces an approved preview with provenance and skips unchanged inputs', function () {
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);
    $store = app(FileStore::class);
    $base = $store->store(mapPng(16, 120, 100, 90), 'base.png');
    $rough = $store->store(mapPng(16, 200, 200, 200), 'rough.png');
    $source = app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => $base, 'roughness' => $rough]), ReviewState::Approved);

    expect(RenderPreview::sourceFor($variant)?->is($source))->toBeTrue();

    $run = RenderPreview::forVariant($variant, User::factory()->create());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->result['maps'])->toBe(['base_color', 'roughness']);

    $preview = Representation::query()->findOrFail($run->result['representation_id']);
    $file = $preview->fileFor('render');

    expect($preview->isApproved())->toBeTrue()
        ->and($preview->target->slug)->toBe('preview')
        ->and($preview->quality->slug)->toBe('preview')
        ->and($preview->kind)->toBe(Representation::KIND_IMAGE)
        ->and($file?->width_px)->toBe(512)
        ->and($file?->original_name)->toBe($variant->code.'_preview.png')
        ->and($preview->metadata['render']['input_hash'])->toBe(RenderPreview::inputHash($source));

    $event = ProvenanceEvent::query()->where('action', 'rendered')->sole();
    expect($event->inputs->pluck('id')->sort()->values()->all())->toBe(collect([$base->id, $rough->id])->sort()->values()->all())
        ->and($event->outputs->first()?->is($file))->toBeTrue()
        ->and($event->parameters['size'])->toBe(512);

    $again = RenderPreview::forVariant($variant);
    expect($again->result['skipped'])->toBe('already rendered for these inputs')
        ->and(Representation::query()->where('target_id', $preview->target_id)->count())->toBe(1);

    $forced = RenderPreview::forVariant($variant, null, force: true);
    expect($forced->result['representation_id'])->not->toBe($preview->getKey())
        ->and($preview->refresh()->review_state)->toBe(ReviewState::Superseded);

    $previews = app(MaterialPreviews::class)->filesFor(Material::query()->whereKey($material->getKey())->get());
    expect($previews[$material->getKey()]->id)->toBe(Representation::query()->findOrFail($forced->result['representation_id'])->fileFor('render')?->id);
});

test('approving a canonical set queues a render when auto rendering is on, and the command fills gaps', function () {
    config(['opal.previews.auto_render' => true]);
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Slate']);
    $base = app(FileStore::class)->store(mapPng(16, 90, 90, 100), 'base.png');

    app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => $base]), ReviewState::Approved);

    expect(WorkerRun::query()->where('type', 'render_preview')->count())->toBe(1)
        ->and(RenderPreview::renderedFor($variant))->not->toBeNull();

    config(['opal.previews.auto_render' => false]);
    $other = app(AddVariant::class)->handle($material, ['colourway' => 'Ash']);
    app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($other, 'pbr', '1k', ['base_color' => $base]), ReviewState::Approved);

    $this->artisan('opal:previews:render', ['--material' => $material->code, '--missing' => true, '--sync' => true])
        ->expectsOutputToContain($other->code)
        ->assertSuccessful();

    expect(RenderPreview::renderedFor($other))->not->toBeNull()
        ->and(WorkerRun::query()->where('type', 'render_preview')->count())->toBe(2);

    $this->artisan('opal:previews:render', ['--material' => $material->code, '--missing' => true])->assertSuccessful();
    expect(WorkerRun::query()->where('type', 'render_preview')->count())->toBe(2);
});

test('contributors can request a render from the material page', function () {
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);

    $component = Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))->test('pages::materials.show', ['material' => $material]);
    $component->call('renderPreview', $variant->id);
    expect(WorkerRun::query()->where('type', 'render_preview')->count())->toBe(0);

    app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => app(FileStore::class)->store(mapPng(16, 90, 120, 100), 'base.png')]), ReviewState::Approved);
    $component->call('renderPreview', $variant->id);

    expect(WorkerRun::query()->where('type', 'render_preview')->count())->toBe(1)
        ->and(RenderPreview::renderedFor($variant))->not->toBeNull();
});
