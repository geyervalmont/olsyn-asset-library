<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Email approval and removal ledger for the shared workspace. Membership and
 * actual permissions continue to live in the existing tenant and role tables.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $role
 * @property string $status
 * @property int|null $user_id
 * @property Carbon|null $expires_at
 */
#[Fillable(['tenant_id', 'email', 'role', 'status', 'user_id', 'changed_by', 'expires_at'])]
class WorkspaceAccess extends Model
{
    protected $table = 'workspace_access';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
