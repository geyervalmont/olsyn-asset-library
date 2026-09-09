<?php

namespace App\Library;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileInfo;
use Symfony\Component\Mime\MimeTypes;

/**
 * Puts bytes into the library as an immutable, content-addressed File.
 * Identical bytes are stored once and return the existing record.
 */
class FileStore
{
    public function disk(): string
    {
        return (string) config('opal.files_disk');
    }

    /**
     * Store an uploaded file, a path, or raw bytes.
     */
    public function store(UploadedFile|SplFileInfo|string $input, ?string $originalName = null, ?string $mimeType = null): File
    {
        [$contents, $originalName, $mimeType] = $this->read($input, $originalName, $mimeType);

        $sha256 = hash('sha256', $contents);

        $existing = File::query()->where('sha256', $sha256)->first();

        if ($existing !== null) {
            return $existing;
        }

        $extension = $this->extensionFor($originalName, $mimeType);
        $objectKey = $this->objectKey($sha256, $extension);

        Storage::disk($this->disk())->put($objectKey, $contents);

        // Another worker can finish the same deterministic output between the
        // lookup above and this insert. firstOrCreate's create-or-first path
        // catches that unique-key race and returns the winner instead of
        // failing one of two otherwise valid jobs.
        return File::query()->firstOrCreate(['sha256' => $sha256], [
            'disk' => $this->disk(),
            'object_key' => $objectKey,
            'kind' => $this->kindFor($mimeType),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'original_name' => $originalName,
            'bytes' => strlen($contents),
            ...$this->imageAttributes($contents, $mimeType),
        ]);
    }

    public function objectKey(string $sha256, ?string $extension): string
    {
        $prefix = trim((string) config('opal.files_prefix'), '/');
        $name = $extension === null ? $sha256 : $sha256.'.'.$extension;

        return sprintf('%s/%s/%s/%s', $prefix, substr($sha256, 0, 2), substr($sha256, 2, 2), $name);
    }

    public function kindFor(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            in_array($mimeType, ['application/zip', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip'], true) => 'archive',
            in_array($mimeType, ['application/pdf', 'text/plain', 'text/markdown', 'text/csv'], true) => 'document',
            str_starts_with($mimeType, 'model/') => 'model',
            default => 'other',
        };
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function read(UploadedFile|SplFileInfo|string $input, ?string $originalName, ?string $mimeType): array
    {
        if ($input instanceof UploadedFile) {
            return [
                (string) $input->get(),
                $originalName ?? $input->getClientOriginalName(),
                $mimeType ?? ($input->getMimeType() ?: 'application/octet-stream'),
            ];
        }

        if ($input instanceof SplFileInfo) {
            $path = $input->getPathname();
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new InvalidArgumentException("Unable to read [{$path}].");
            }

            return [
                $contents,
                $originalName ?? $input->getFilename(),
                $mimeType ?? $this->detectMime($contents),
            ];
        }

        return [$input, $originalName, $mimeType ?? $this->detectMime($input)];
    }

    private function detectMime(string $contents): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_buffer($finfo, $contents);

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function extensionFor(?string $originalName, string $mimeType): ?string
    {
        $fromName = $originalName === null ? '' : strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($fromName !== '') {
            return Str::limit($fromName, 16, '');
        }

        if ($mimeType === 'application/octet-stream') {
            return null;
        }

        $guessed = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;

        return $guessed === null ? null : strtolower($guessed);
    }

    /**
     * @return array<string, mixed>
     */
    private function imageAttributes(string $contents, string $mimeType): array
    {
        if (! str_starts_with($mimeType, 'image/')) {
            return [];
        }

        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            return [];
        }

        return [
            'width_px' => $info[0],
            'height_px' => $info[1],
            'bit_depth' => $info['bits'] ?? null,
            'colour_space' => ($info['channels'] ?? null) === 3 || ($info['channels'] ?? null) === 4 ? 'srgb' : null,
        ];
    }
}
