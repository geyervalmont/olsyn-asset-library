<?php

namespace App\Actions\Clients;

use App\Events\Realtime\SessionUpdated;
use App\Models\ClientSession;
use App\Support\Realtime;

/**
 * Record a session change (start, heartbeat, end) and tell the owner's
 * browser tabs.
 */
class TouchClientSession
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ClientSession $session, array $attributes = []): ClientSession
    {
        $session->fill($attributes + ['last_seen_at' => now()])->save();

        Realtime::publish(new SessionUpdated($session));

        return $session;
    }

    public function end(ClientSession $session): ClientSession
    {
        $session->forceFill(['ended_at' => now()])->save();

        Realtime::publish(new SessionUpdated($session));

        return $session;
    }
}
