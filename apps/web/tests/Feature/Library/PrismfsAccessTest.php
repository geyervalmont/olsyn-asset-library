<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\Drive;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function accessPng(int $grey): string
{
    $image = imagecreatetruecolor(2, 2);
    imagefilledrectangle($image, 0, 0, 2, 2, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);

    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    $ashen = app(AddVariant::class)->handle($this->material, ['colourway' => 'Ashen']);
    $this->file = app(FileStore::class)->store(accessPng(100), 'base.png');
    app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($ashen, 'pbr', '2k', ['base_color' => $this->file]), ReviewState::Approved);
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    $this->drive = Drive::factory()->create(['name' => 'Studio share', 'root_path' => 'materials']);
    $this->token = $this->drive->issueToken();
    $this->path = '/materials/Carpet/Academix/Ashen/pbr/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png';
});

function accessEvent(string $requestId, string $path, array $overrides = []): array
{
    return array_merge([
        'request_id' => $requestId,
        'occurred_at' => '2026-09-06T10:00:00Z',
        'principal' => 'uid:1000',
        'operation' => 'read',
        'path' => $path,
        'result' => 'allow',
        'bytes' => 4096,
        'duration_ms' => 0.42,
    ], $overrides);
}

test('prismfs access batches need the drive token', function () {
    $url = route('prismfs.drives.accesses', $this->drive);

    $this->postJson($url, ['events' => [accessEvent('r1', $this->path)]])->assertUnauthorized();
    $this->postJson($url, ['events' => [accessEvent('r1', $this->path)]], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    $this->postJson($url, ['events' => []], ['Authorization' => 'Bearer '.$this->token])->assertUnprocessable();
    $this->postJson($url, ['events' => [['request_id' => 'r1']]], ['Authorization' => 'Bearer '.$this->token])->assertUnprocessable();

    expect(FileAccess::query()->count())->toBe(0);
});

test('drive reads map to library files, unmatched paths are kept, and retries do not duplicate', function () {
    $url = route('prismfs.drives.accesses', $this->drive);
    $headers = ['Authorization' => 'Bearer '.$this->token];
    $events = [
        accessEvent('r1', $this->path),
        accessEvent('r2', '/materials/.Trash-1000', ['operation' => 'lookup', 'result' => 'error', 'bytes' => null, 'principal' => null]),
    ];

    $this->postJson($url, ['events' => $events], $headers)->assertStatus(202)->assertJson(['accepted' => 2, 'recorded' => 2]);
    $this->postJson($url, ['events' => $events], $headers)->assertStatus(202)->assertJson(['accepted' => 2, 'recorded' => 0]);

    $reads = FileAccess::query()->orderBy('id')->get();

    expect($reads)->toHaveCount(2)
        ->and($reads[0]->file_id)->toBe($this->file->getKey())
        ->and($reads[0]->channel)->toBe(FileAccess::CHANNEL_PRISMFS)
        ->and($reads[0]->drive_id)->toBe($this->drive->getKey())
        ->and($reads[0]->principal)->toBe('uid:1000')
        ->and($reads[0]->action)->toBe('read')
        ->and($reads[0]->result)->toBe('allow')
        ->and($reads[0]->bytes)->toBe(4096)
        ->and((float) $reads[0]->duration_ms)->toBe(0.42)
        ->and($reads[0]->accessed_at->toIso8601String())->toBe('2026-09-06T10:00:00+00:00')
        ->and($reads[1]->file_id)->toBeNull()
        ->and($reads[1]->path)->toBe('/materials/.Trash-1000')
        ->and($reads[1]->result)->toBe('error');

    expect($this->file->accesses()->count())->toBe(1);
});

test('the material page lists drive reads next to web downloads', function () {
    $this->postJson(route('prismfs.drives.accesses', $this->drive), ['events' => [accessEvent('r1', $this->path)]], ['Authorization' => 'Bearer '.$this->token])->assertStatus(202);
    FileAccess::create(['file_id' => $this->file->getKey(), 'channel' => FileAccess::CHANNEL_WEB, 'action' => 'download', 'user_id' => User::factory()->create(['name' => 'Harrison'])->getKey(), 'accessed_at' => now()]);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSee('Studio share')
        ->assertSee('uid:1000')
        ->assertSee('Harrison')
        ->assertSeeHtml('data-test="access-row"');
});
