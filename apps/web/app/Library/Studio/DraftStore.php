<?php

namespace App\Library\Studio;

use App\Models\StudioDraft;
use App\Models\StudioRevision;
use App\Models\SynthesisRun;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DraftStore
{
    public const MAPS = ['base_color', 'normal', 'roughness', 'height', 'metallic'];

    public function disk(): string
    {
        return (string) config('synthesis.disk');
    }

    /** @return array<string, mixed> */
    public function put(string $bytes, string $role): array
    {
        $info = @getimagesizefromstring($bytes);
        throw_if($info === false, \RuntimeException::class, 'Unreadable image.');
        $sha = hash('sha256', $bytes);
        $path = config('synthesis.prefix').'/'.Str::uuid().'/'.$role.'.png';
        throw_unless(Storage::disk($this->disk())->put($path, $bytes), \RuntimeException::class, 'Could not save draft image.');

        return ['disk' => $this->disk(), 'path' => $path, 'sha256' => $sha, 'bytes' => strlen($bytes), 'width' => $info[0], 'height' => $info[1], 'bit_depth' => $info['bits'] ?? 8, 'colour_space' => in_array($role, ['source', 'prepared', 'cleaned', 'base_color'], true) ? 'srgb' : 'linear'];
    }

    /** @param array<string, mixed> $asset */
    public function bytes(array $asset): string
    {
        $bytes = Storage::disk($asset['disk'])->get($asset['path']);
        throw_unless(is_string($bytes) && hash_equals($asset['sha256'], hash('sha256', $bytes)), \RuntimeException::class, 'Draft artifact is missing or damaged.');

        return $bytes;
    }

    public function fromPhoto(UploadedFile $photo, string $name): StudioDraft
    {
        $image = new \Imagick;
        $original = (string) $photo->get();
        $image->readImageBlob($original);
        $image->setIteratorIndex(0);
        $image->autoOrient();
        $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        $image->stripImage();
        $image->thumbnailImage(2048, 2048, true, true);
        $image->setImageFormat('png');
        $image->setImageDepth(8);
        $source = $this->put($image->getImageBlob(), 'source');
        $image->clear();
        // Original capture stays private and outside the globally addressable library File table.
        $originalPath = config('synthesis.prefix').'/'.Str::uuid().'/original';
        throw_unless(Storage::disk($this->disk())->put($originalPath, $original), \RuntimeException::class, 'Could not save original photo.');

        return DB::transaction(function () use ($source, $originalPath, $original, $name): StudioDraft {
            $draft = StudioDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'tenant_id' => Tenant::current()?->id, 'name' => $name, 'state' => 'active']);
            $revision = $draft->revisions()->create(['document' => ['schema' => 1, 'source' => $source, 'original' => ['disk' => $this->disk(), 'path' => $originalPath, 'sha256' => hash('sha256', $original)], 'width_mm' => null, 'height_mm' => null, 'resolution' => 1024, 'cleanup' => 0, 'crop' => ['x' => 50, 'y' => 50, 'size' => 100]], 'artifacts' => []]);
            $draft->update(['head_id' => $revision->id]);

            return $draft;
        });
    }

    /** @param array<string, mixed> $document
     * @param  array<string, array<string, mixed>>|null  $artifacts
     */
    public function revise(StudioDraft $draft, int $expectedHead, array $document, ?array $artifacts = null): StudioRevision
    {
        return DB::transaction(function () use ($draft, $expectedHead, $document, $artifacts): StudioRevision {
            $locked = StudioDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if ($locked->head_id !== $expectedHead || $locked->state !== 'active') {
                throw ValidationException::withMessages(['draft' => 'This draft changed in another session. Reopen it before continuing.']);
            }
            $revision = $locked->revisions()->create(['parent_id' => $expectedHead, 'document' => $document, 'artifacts' => $artifacts]);
            $locked->update(['head_id' => $revision->id]);

            return $revision;
        });
    }

    public function fork(StudioRevision $revision): StudioDraft
    {
        return DB::transaction(function () use ($revision): StudioDraft {
            $draft = StudioDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'tenant_id' => Tenant::current()?->id, 'name' => $revision->draft->name.' — colourway', 'source_representation_id' => $revision->representation_id ?? $revision->draft->source_representation_id]);
            $copy = $draft->revisions()->create(['parent_id' => $revision->id, 'document' => $revision->document, 'artifacts' => $revision->artifacts]);
            $draft->update(['head_id' => $copy->id]);

            return $draft;
        });
    }

    public function generate(StudioRevision $revision): SynthesisRun
    {
        if (! config('synthesis.enabled') || ! config('synthesis.model_path') || ! config('synthesis.image') || ! preg_match('/^[a-f0-9]{64}$/', (string) config('synthesis.model_sha256'))) {
            throw ValidationException::withMessages(['generation' => 'The self-hosted worker is not configured yet. Your draft is saved.']);
        }
        if (($revision->document['cleanup'] ?? 0) > 0 && ! config('synthesis.cleanup_enabled')) {
            throw ValidationException::withMessages(['generation' => 'Glare cleanup is not configured. Disable cleanup to run CHORD.']);
        }

        return DB::transaction(function () use ($revision): SynthesisRun {
            // Lock the tenant to serialize its quota checks, including double-clicks.
            Tenant::query()->lockForUpdate()->findOrFail($revision->draft->tenant_id);
            $draft = StudioDraft::query()->lockForUpdate()->findOrFail($revision->studio_draft_id);
            abort_unless($draft->state === 'active' && $draft->head_id === $revision->id, 409);
            $existing = $revision->run()->first();
            if ($existing !== null) {
                return $existing;
            }
            $count = SynthesisRun::query()->whereHas('revision.draft', fn ($q) => $q->where('tenant_id', $revision->draft->tenant_id))->where('created_at', '>=', now()->startOfDay())->count();
            if ($count >= (int) config('synthesis.daily_runs')) {
                throw ValidationException::withMessages(['generation' => 'Today’s generation limit has been reached. Your draft is saved.']);
            }

            return SynthesisRun::create(['uuid' => (string) Str::uuid(), 'studio_revision_id' => $revision->id, 'worker_token' => Str::random(64), 'manifest' => ['runtime' => array_intersect_key((array) config('synthesis'), array_flip(['image', 'profile', 'model_disk', 'model_path', 'model_sha256']))], 'deadline_at' => now()->addSeconds((int) config('synthesis.max_seconds'))]);
        });
    }

    /** Apply inexpensive appearance edits without re-running inference.
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>
     */
    public function adjust(StudioRevision $revision, array $settings): array
    {
        $maps = $revision->artifacts ?? [];
        if (isset($maps['base_color']) && ($settings['tint_amount'] ?? 0) > 0) {
            $image = new \Imagick;
            $image->readImageBlob($this->bytes($maps['base_color']));
            $image->colorizeImage($settings['tint'], 'gray('.(int) $settings['tint_amount'].'%)');
            $image->stripImage();
            $maps['base_color'] = $this->put($image->getImageBlob(), 'base_color');
            $image->clear();
        }
        if (isset($maps['roughness']) && ($settings['roughness'] ?? '') !== '') {
            $image = new \Imagick;
            $image->newImage($maps['roughness']['width'], $maps['roughness']['height'], new \ImagickPixel('gray('.(100 * (float) $settings['roughness']).'%)'), 'png');
            $image->setImageDepth(8);
            $maps['roughness'] = $this->put($image->getImageBlob(), 'roughness');
            $image->clear();
        }

        return $maps;
    }
}
