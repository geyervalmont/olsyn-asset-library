<?php

namespace App\Console\Commands;

use App\Mail\WorkspaceWelcome;
use App\Support\SharedWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendOnboardingTestCommand extends Command
{
    protected $signature = 'opal:onboarding:test {email : Recipient for one test email}';

    protected $description = 'Send the onboarding template without changing team access';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }
        $tenant = app(SharedWorkspace::class)->tenant();
        $message = Mail::to($email)->send(new WorkspaceWelcome($tenant === null ? 'Olsyn' : $tenant->name, 'Designer', true));
        $this->info('Mail transport accepted onboarding test for '.$email.'. Message ID: '.($message?->getMessageId() ?? 'unavailable'));

        return self::SUCCESS;
    }
}
