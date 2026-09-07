<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OlsynAccess;
use Illuminate\Console\Command;

class LinkWorkosIdentity extends Command
{
    protected $signature = 'opal:link-workos {email} {workos_id}';

    protected $description = 'Link an existing Opal account to a centrally granted WorkOS identity';

    public function handle(OlsynAccess $access): int
    {
        $user = User::whereRaw('lower(email) = ?', [strtolower($this->argument('email'))])->firstOrFail();
        $identity = $this->argument('workos_id');
        $decision = $access->decision(['workos_id' => $identity]);
        if (! $decision['allowed'] || strtolower($decision['email'] ?? '') !== strtolower($user->email)
            || ($user->workos_id && $user->workos_id !== $identity)) {
            $this->error('The identity must have an active central grant and match this account. Existing identity links cannot be replaced.');

            return self::FAILURE;
        }
        $user->forceFill(['workos_id' => $identity])->save();
        $this->info('Identity linked. Existing password, passkeys and workspace roles are preserved.');

        return self::SUCCESS;
    }
}
