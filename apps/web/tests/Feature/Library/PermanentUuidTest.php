<?php

use App\Models\Material;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => $this->seed(LibrarySeeder::class));

test('materials and variants have immutable UUIDv7 public identities and resolve by either identity', function () {
    $material = Material::factory()->create();
    $variant = Variant::factory()->for($material)->create();

    expect(Str::isUuid($material->uuid, version: 7))->toBeTrue()
        ->and(Str::isUuid($variant->uuid, version: 7))->toBeTrue()
        ->and(Material::resolveCode(strtoupper($material->uuid))?->id)->toBe($material->id)
        ->and(Material::resolveCode($material->code)?->id)->toBe($material->id)
        ->and(Variant::resolveCode($variant->uuid)?->id)->toBe($variant->id)
        ->and(Variant::resolveCode($variant->code)?->id)->toBe($variant->id);

    $uuid = $material->uuid;
    $material->update(['name' => 'Renamed material']);
    expect($material->refresh()->uuid)->toBe($uuid);
    $material->uuid = (string) Str::uuid7();
    expect(fn () => $material->save())->toThrow(LogicException::class, 'immutable');
    $variant->uuid = (string) Str::uuid7();
    expect(fn () => $variant->save())->toThrow(LogicException::class, 'immutable');
});

test('the database assigns UUIDv7 for bulk writers and rejects identity mutation', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL database guard');
    }
    $material = Material::factory()->create();
    $attributes = $material->getAttributes();
    unset($attributes['id'], $attributes['uuid']);
    $attributes['code'] .= '-BULK';
    $id = DB::table('materials')->insertGetId($attributes);
    expect(Str::isUuid(DB::table('materials')->where('id', $id)->value('uuid'), version: 7))->toBeTrue();
    expect(fn () => DB::table('materials')->where('id', $id)->update(['uuid' => (string) Str::uuid7()]))
        ->toThrow(QueryException::class, 'immutable');
});

test('migration backfills existing records without changing their integer identities', function () {
    $material = Material::factory()->create();
    $variant = Variant::factory()->for($material)->create();
    $migration = require database_path('migrations/2026_09_23_000000_add_permanent_material_uuids.php');
    $migration->down();
    $migration->up();
    expect($material->refresh()->id)->toBe($material->id)
        ->and(Str::isUuid($material->uuid, version: 7))->toBeTrue()
        ->and(Str::isUuid($variant->refresh()->uuid, version: 7))->toBeTrue();
});
