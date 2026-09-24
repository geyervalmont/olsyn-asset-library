<?php

namespace App\Actions\Clients;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Events\Realtime\CommandQueued;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\User;
use App\Support\Realtime;

/**
 * Ask a live client session to do something; the session hears about it
 * over its private channel and can also poll for queued commands.
 */
class IssueClientCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(ClientSession $session, User $issuer, CommandType $type, array $payload): ClientCommand
    {
        $session->refresh();
        abort_unless($session->user_id === $issuer->getKey() && $session->isLive(), 409, 'This application is no longer connected.');
        if ($type === CommandType::Apply) {
            abort_unless($session->supports(isset($payload['studio']) ? 'draft.apply' : 'material.apply'), 422, 'This application needs an extension update to apply materials from the website.');
        }

        $command = $session->commands()->create([
            'issued_by' => $issuer->getKey(),
            'type' => $type,
            'payload' => $payload,
            'status' => CommandStatus::Queued,
        ]);

        Realtime::publish(new CommandQueued($command));

        return $command;
    }
}
