<?php

namespace App\Jobs\Workspace;

use App\Mail\WorkspaceWelcome;
use App\Models\Tenant;
use App\Models\WorkspaceAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

class SendInvitation implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $accessId, public string $queuedAt) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        $entry = $this->entry();
        if ($entry === null || $entry->email_sent_at !== null || $entry->status !== 'pending' || ! $entry->expires_at?->isFuture()) {
            return;
        }
        $tenant = Tenant::findOrFail($entry->tenant_id);
        Mail::to($entry->email)->send(new WorkspaceWelcome($tenant->name, match ($entry->role) {
            'editor' => 'Designer', 'admin' => 'Administrator', default => 'Viewer',
        }));
        $this->entry()?->forceFill(['email_sent_at' => now(), 'email_error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->entry()?->forceFill(['email_error' => 'Email could not be sent. Check mail delivery and resend the invitation.'])->save();
    }

    private function entry(): ?WorkspaceAccess
    {
        return WorkspaceAccess::query()->where('email_queued_at', $this->queuedAt)->find($this->accessId);
    }
}
