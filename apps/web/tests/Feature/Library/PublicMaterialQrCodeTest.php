<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Library\FileStore;
use App\Library\QrCodes\MaterialQrCodes;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();

    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->admin = User::factory()->withTenant($this->tenant, Role::Admin)->create();
    $this->material = Material::factory()->create([
        'name' => 'Public Travertine',
        'description' => 'Warm limestone with a fine linear grain.',
        'collection' => 'Natural stone',
    ]);
    $this->honed = app(AddVariant::class)->handle($this->material, ['finish' => 'Honed'], overrides: ['dominant_hex' => '#bca98f']);
    $this->filled = app(AddVariant::class)->handle($this->material, ['finish' => 'Filled']);

    $image = imagecreatetruecolor(12, 12);
    imagefilledrectangle($image, 0, 0, 11, 11, imagecolorallocate($image, 188, 169, 143));
    ob_start();
    imagepng($image);
    $this->preview = app(FileStore::class)->store((string) ob_get_clean(), 'travertine-preview.png', 'image/png');
    $representation = app(CreateRepresentation::class)->handle($this->honed, 'preview', 'preview', ['render' => $this->preview]);
    app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved);
});

afterEach(fn () => Tenant::forgetCurrent());

test('publishers can generate material and colourway QR options', function () {
    $options = app(MaterialQrCodes::class)->options($this->material, $this->material->variants);

    expect($options)->toHaveCount(3)
        ->and($options[0]['label'])->toBe('Material only')
        ->and($options[1]['label'])->toContain('Honed')
        ->and($options[1]['detail'])->toBe($this->honed->code)
        ->and($options[1]['target'])->toContain('/m/'.$this->material->id)
        ->and($options[1]['target'])->not->toContain($this->material->code)
        ->and($options[1]['image'])->toContain('/qr.svg?')
        ->and($options[1]['download'])->toContain('download=1');

    Livewire::actingAs($this->admin)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSeeHtml('data-test="open-material-qr"')
        ->assertSeeHtml('data-test="material-qr-modal"')
        ->assertSee('Material only')
        ->assertSee('Colourway — Honed');

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertDontSeeHtml('data-test="open-material-qr"')
        ->assertDontSeeHtml('data-test="material-qr-modal"');
});

test('the signed material destination is public and can select a colourway', function () {
    $qrCodes = app(MaterialQrCodes::class);
    $materialUrl = $qrCodes->publicUrl($this->material);
    $variantUrl = $qrCodes->publicUrl($this->material, $this->honed);

    $this->get(route('materials.public', $this->material))->assertForbidden();

    $this->get($materialUrl)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('data-test="public-material"', false)
        ->assertSee('Public Travertine')
        ->assertSee('Honed')
        ->assertSee('Warm limestone with a fine linear grain.')
        ->assertDontSee('Provenance')
        ->assertDontSee('Apply in Revit');

    $this->get($variantUrl)
        ->assertOk()
        ->assertSee($this->honed->code)
        ->assertDontSee($this->filled->code);

    $tampered = preg_replace('/variant='.$this->honed->id.'/', 'variant='.$this->filled->id, $variantUrl);
    $this->get((string) $tampered)->assertForbidden();
});

test('the signed SVG itself is public, downloadable, and tied to its destination', function () {
    $qrCodes = app(MaterialQrCodes::class);
    $inline = $qrCodes->imageUrl($this->material, $this->honed);
    $download = $qrCodes->imageUrl($this->material, $this->honed, true);

    $this->get($inline)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'inline; filename="'.Str::slug($this->honed->code).'-qr.svg"')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertSee('<?xml', false)
        ->assertSee('<svg', false);

    $this->get($download)
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.Str::slug($this->honed->code).'-qr.svg"');

    $this->get(route('materials.public.qr', $this->material))->assertForbidden();
});

test('the public page streams only its signed rendered preview and audits the read', function () {
    $url = app(MaterialQrCodes::class)->previewUrl($this->material, $this->honed);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=300, public')
        ->assertStreamedContent($this->preview->contents());

    $access = FileAccess::query()->sole();

    expect($access->file_id)->toBe($this->preview->id)
        ->and($access->channel)->toBe(FileAccess::CHANNEL_PUBLIC_QR)
        ->and($access->principal)->toBe('public-qr')
        ->and($access->user_id)->toBeNull();

    $this->get(route('materials.public.preview', [$this->material, $this->honed]))->assertForbidden();
});

test('a QR cannot mix a material with another material colourway', function () {
    $other = Material::factory()->create();
    $otherVariant = app(AddVariant::class)->handle($other, ['finish' => 'Polished']);

    expect(fn () => app(MaterialQrCodes::class)->publicUrl($this->material, $otherVariant))
        ->toThrow(InvalidArgumentException::class);
});
