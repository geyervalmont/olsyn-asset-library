<?php

namespace App\Actions\Drives;

use App\Events\Drives\IntakeSubmitted;
use App\Library\Drives\DrivePath;
use App\Models\DriveIntakeFile;
use App\Models\DriveIntakeSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ManageIntake
{
    public function create(User $user, string $name): DriveIntakeSession
    {
        abort_unless($user->can('materials.contribute'), 403);

        return DB::transaction(function () use ($user, $name): DriveIntakeSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(DriveIntakeSession::query()->where('user_id', $user->id)->open()->count() >= config('opal.drive.intake_active_sessions'), 409, 'Close an existing upload session before starting another.');

            return DriveIntakeSession::create(['user_id' => $user->id, 'tenant_id' => Tenant::current()?->id, 'name' => $name, 'expires_at' => now()->addDay()]);
        });
    }

    /** Idempotent, one active root upload folder per person and workspace. */
    public function inbox(User $user): DriveIntakeSession
    {
        abort_unless($user->can('materials.contribute'), 403);
        $tenant = Tenant::current();
        abort_unless($tenant !== null && $user->canAccessTenant($tenant), 403);

        return DB::transaction(function () use ($user, $tenant): DriveIntakeSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DriveIntakeSession::query()->where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)->where('is_inbox', true)->open()->first();

            return $existing ?? DriveIntakeSession::create([
                'user_id' => $user->id, 'tenant_id' => $tenant->id, 'is_inbox' => true,
                'name' => 'Drive upload · '.now()->format('d M Y H:i'), 'expires_at' => null,
            ]);
        });
    }

    public function reserve(DriveIntakeSession $session, string $path, int $bytes, string $sha256): DriveIntakeFile
    {
        $path = DrivePath::validate($path);
        abort_unless($bytes >= 1 && $bytes <= config('opal.drive.intake_file_bytes') && preg_match('/^[a-f0-9]{64}$/', $sha256), 422, 'Invalid file size or checksum.');

        return DB::transaction(function () use ($session, $path, $bytes, $sha256): DriveIntakeFile {
            $session = DriveIntakeSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->acceptsUploads(), 409, 'This upload session is closed or expired.');
            $pathHash = hash('sha256', mb_strtolower($path));
            $existing = $session->files()->where('path_hash', $pathHash)->first();
            if ($existing !== null) {
                abort_unless($existing->sha256 === $sha256 && $existing->bytes === $bytes, 409, 'This path is already reserved for different content.');

                return $existing;
            }
            $foldedPath = mb_strtolower($path);
            foreach ($session->files()->pluck('path') as $reservedPath) {
                $foldedReserved = mb_strtolower($reservedPath);
                abort_if(str_starts_with($foldedPath, $foldedReserved.'/') || str_starts_with($foldedReserved, $foldedPath.'/'), 409, 'A file cannot also be a folder in this upload batch.');
            }
            abort_if($session->files()->count() >= config('opal.drive.intake_max_files')
                || $session->files()->sum('bytes') + $bytes > config('opal.drive.intake_batch_bytes'), 422, 'This upload batch is full. Start another batch.');
            $file = $session->files()->make(['path' => $path, 'path_hash' => $pathHash, 'bytes' => $bytes,
                'sha256' => $sha256, 'disk' => config('opal.drive.intake_disk'),
                'object_key' => 'opal/intake/'.$session->uuid.'/'.$pathHash.'/'.$sha256]);
            $file->save();

            return $file;
        });
    }

    /** @param resource $input */
    public function upload(DriveIntakeSession $session, DriveIntakeFile $file, $input): void
    {
        abort_unless($file->drive_intake_session_id === $session->id, 404);
        abort_unless($session->acceptsUploads(), 409, 'This upload session is closed or expired.');
        // Bounded disk spool: hashing never loads a large texture into PHP memory.
        $spool = tmpfile();
        if ($spool === false) {
            throw new \RuntimeException('Cannot stage this upload.');
        }
        try {
            $hash = hash_init('sha256');
            $count = 0;
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Upload stream failed.');
                }
                $count += strlen($chunk);
                abort_if($count > $file->bytes, 413, 'Upload exceeds the reserved size.');
                hash_update($hash, $chunk);
                if (fwrite($spool, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Upload spool is full.');
                }
            }
            if ($count !== $file->bytes || ! hash_equals($file->sha256, hash_final($hash))) {
                throw ValidationException::withMessages(['file' => 'The upload does not match its reserved size and SHA-256. Retry the file.']);
            }
            rewind($spool);
            // Lock order matches submit/cancel/reserve. Closing a batch cannot race a commit.
            DB::transaction(function () use ($session, $file, $spool): void {
                $session = DriveIntakeSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                abort_unless($session->acceptsUploads(), 409, 'This upload session is closed or expired.');
                $file = DriveIntakeFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
                if ($file->uploaded_at !== null) {
                    return;
                }
                if (! Storage::disk($file->disk)->put($file->object_key, $spool, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Upload storage is unavailable. Retry the file.');
                }
                $file->update(['uploaded_at' => now()]);
            });
        } finally {
            fclose($spool);
        }
    }

    public function submit(DriveIntakeSession $session): DriveIntakeSession
    {
        return DB::transaction(function () use ($session): DriveIntakeSession {
            $session = DriveIntakeSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'submitted') {
                return $session;
            }
            abort_unless($session->acceptsUploads(), 409, 'This upload session is closed or expired.');
            abort_if(! $session->files()->exists() || $session->files()->whereNull('uploaded_at')->exists(), 409, 'Finish uploading every file before submitting this batch.');
            $session->update(['status' => 'submitted', 'submitted_at' => now()]);
            // Deliberately no ingestion job: future processors subscribe to this event.
            DB::afterCommit(fn () => event(new IntakeSubmitted($session->uuid)));

            return $session;
        });
    }

    public function cancel(DriveIntakeSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session = DriveIntakeSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->status === 'submitted', 409, 'A submitted batch cannot be cancelled here.');
            $session->update(['status' => 'cancelled']);
        });
    }
}
