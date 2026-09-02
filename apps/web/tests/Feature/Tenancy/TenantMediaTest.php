<?php

use App\MediaLibrary\TenantPathGenerator;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TenantMediaOwner extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia;

    protected $guarded = [];
}

afterEach(function () {
    Tenant::forgetCurrent();
});

test('media is assigned to and scoped by the current tenant', function () {
    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $firstTenant->makeCurrent();
    $firstMedia = Media::query()->create(mediaAttributes($user, 'first.txt'));

    $secondTenant->makeCurrent();
    $secondMedia = Media::query()->create(mediaAttributes($user, 'second.txt'));

    expect(Media::query()->pluck('id')->all())->toBe([$secondMedia->getKey()]);

    $firstTenant->makeCurrent();

    expect(Media::query()->pluck('id')->all())->toBe([$firstMedia->getKey()])
        ->and(app(TenantPathGenerator::class)->getPath($firstMedia))
        ->toBe("tenants/{$firstTenant->getKey()}/media/{$firstMedia->uuid}/");

    Tenant::forgetCurrent();

    expect(Media::query()->count())->toBe(0);
});

test('tenant scoped media cannot be created without a current tenant', function () {
    $user = User::factory()->create();

    expect(fn () => Media::query()->create(mediaAttributes($user, 'orphan.txt')))
        ->toThrow(LogicException::class, 'without a current tenant');
});

test('media library stores tenant media on the configured s3 disk', function () {
    Schema::create('tenant_media_owners', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('name');
        $table->timestamps();
    });

    Storage::fake('s3');

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $owner = TenantMediaOwner::query()->create(['name' => 'Example']);

    $media = $owner
        ->addMediaFromString('example contents')
        ->usingFileName('example.txt')
        ->toMediaCollection('documents');

    $path = app(TenantPathGenerator::class)->getPath($media).'example.txt';

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->tenant_id)->toBe($tenant->getKey())
        ->and($media->disk)->toBe('s3');

    Storage::disk('s3')->assertExists($path);
});

/**
 * @return array<string, mixed>
 */
function mediaAttributes(User $user, string $fileName): array
{
    return [
        'model_type' => $user::class,
        'model_id' => $user->getKey(),
        'collection_name' => 'default',
        'name' => pathinfo($fileName, PATHINFO_FILENAME),
        'file_name' => $fileName,
        'mime_type' => 'text/plain',
        'disk' => 'local',
        'conversions_disk' => 'local',
        'size' => 4,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ];
}
