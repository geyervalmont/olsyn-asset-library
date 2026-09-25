<?php

namespace App\Library\Drives;

use App\Models\DriveIntakeFile;
use App\Models\DriveIntakeSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/** One authoritative intake snapshot for every signed-in drive adapter. */
final class IntakeNamespace
{
    /** @return Collection<int, DriveIntakeSession> */
    public function sessions(User $user): Collection
    {
        if (! $user->can('materials.contribute') || ! $user->tokenCan('drive:write')) {
            return collect();
        }

        return DriveIntakeSession::query()->where('user_id', $user->id)->visibleTo($user)->open()
            ->where(fn ($query) => $query->where('is_inbox', false)->orWhere('tenant_id', Tenant::current()?->id))
            ->with(['files' => fn ($query) => $query->whereNotNull('uploaded_at')->orderBy('path')])
            ->orderBy('uuid')->get();
    }

    /** @return array<string, mixed> */
    public function folder(DriveIntakeSession $session, int $revision = 2): array
    {
        return ['id' => $session->uuid, 'path' => DriveLayout::intakePath($session, $revision), 'label' => $session->name,
            'writable' => true, 'expires_at' => $session->expires_at?->toIso8601String(),
            'session_url' => route('api.drive.intake.show', ['session' => $session->uuid], false)];
    }

    /** @return array<string, mixed> */
    public function file(DriveIntakeSession $session, DriveIntakeFile $file, int $revision = 2): array
    {
        return ['path' => DriveLayout::intakePath($session, $revision).'/'.$file->path, 'bytes' => $file->bytes, 'sha256' => $file->sha256,
            'session_id' => $session->uuid, 'file_id' => $file->uuid,
            'content_url' => route('api.drive.intake.content', ['session' => $session->uuid, 'file' => $file->uuid], false)];
    }
}
