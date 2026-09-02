<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SuperAdminCommand extends Command
{
    protected $signature = 'olsyn:super-admin
        {email : The email address of an existing user}
        {--revoke : Remove super-admin access instead of granting it}';

    protected $description = 'Grant or revoke super-admin access for a user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user found for [{$email}].");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($user->is_super_admin === $grant) {
            $this->components->info(sprintf('%s is already %s.', $user->email, $grant ? 'a super-admin' : 'a regular user'));

            return self::SUCCESS;
        }

        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->components->info(sprintf('%s super-admin access for %s.', $grant ? 'Granted' : 'Revoked', $user->email));

        return self::SUCCESS;
    }
}
