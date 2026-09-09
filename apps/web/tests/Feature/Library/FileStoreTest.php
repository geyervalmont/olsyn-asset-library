<?php

use App\Library\FileStore;
use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
});

function pngBytes(int $width = 4, int $height = 3): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

test('bytes are stored once, content-addressed, with image attributes and reusable across output names', function () {
    $store = app(FileStore::class);
    $png = pngBytes(4, 3);

    $first = $store->store($png, 'travertine_albedo.PNG');
    $again = $store->store(UploadedFile::fake()->createWithContent('copy.png', $png));
    $deterministicOutput = $store->store($png, 'another-variant-preview.png');

    expect($again->is($first))->toBeTrue()
        ->and($deterministicOutput->is($first))->toBeTrue()
        ->and(File::query()->count())->toBe(1)
        ->and($first->sha256)->toBe(hash('sha256', $png))
        ->and($first->object_key)->toBe(sprintf('opal/files/%s/%s/%s.png', substr($first->sha256, 0, 2), substr($first->sha256, 2, 2), $first->sha256))
        ->and($first->kind)->toBe('image')
        ->and($first->mime_type)->toBe('image/png')
        ->and($first->extension)->toBe('png')
        ->and($first->original_name)->toBe('travertine_albedo.PNG')
        ->and($first->bytes)->toBe(strlen($png))
        ->and($first->width_px)->toBe(4)
        ->and($first->height_px)->toBe(3)
        ->and($first->contents())->toBe($png);

    Storage::disk(config('opal.files_disk'))->assertExists($first->object_key);
});

test('non-image files get a kind from their mime type and an extension from the name', function () {
    $store = app(FileStore::class);

    $archive = $store->store('PK not really a zip', 'maps.zip', 'application/zip');
    $note = $store->store("supplier notes\n", 'notes.txt');
    $mystery = $store->store(random_bytes(64));

    expect($archive->kind)->toBe('archive')
        ->and($archive->extension)->toBe('zip')
        ->and($archive->width_px)->toBeNull()
        ->and($note->kind)->toBe('document')
        ->and($note->mime_type)->toBe('text/plain')
        ->and($mystery->kind)->toBe('other')
        ->and($mystery->extension)->toBeNull()
        ->and($mystery->object_key)->toEndWith($mystery->sha256);
});

test('a file is immutable', function () {
    $file = app(FileStore::class)->store(pngBytes(), 'a.png');

    expect(fn () => $file->update(['original_name' => 'b.png']))->toThrow(LogicException::class);
});
