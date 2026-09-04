<?php

namespace App\Support;

use Throwable;

/**
 * Where live clients connect, and a broadcast that never breaks a request.
 */
class Realtime
{
    /**
     * What a LAN client needs to open a websocket and authorise channels.
     *
     * @return array{scheme: string, host: string, port: int, key: string|null, auth_endpoint: string}
     */
    public static function clientConfig(): array
    {
        return [
            'scheme' => (string) config('opal.realtime.scheme'),
            'host' => (string) config('opal.realtime.host'),
            'port' => (int) config('opal.realtime.port'),
            'key' => config('opal.realtime.key'),
            'auth_endpoint' => url('/broadcasting/auth'),
        ];
    }

    /**
     * Dispatch a broadcast event; a websocket server that is down is
     * reported, not surfaced to the caller.
     */
    public static function publish(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
