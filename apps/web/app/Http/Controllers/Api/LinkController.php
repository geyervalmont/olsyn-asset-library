<?php

namespace App\Http\Controllers\Api;

use App\Models\DeviceLink;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LinkController
{
    /**
     * Start linking a client to an account.
     *
     * The client shows the code; the person opens verify_url while signed
     * in and approves it. Poll the code with the secret until it is claimed.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:40'],
            'machine' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $secret = Str::random(40);

        $link = DeviceLink::create([
            'code' => DeviceLink::generateCode(),
            'secret_hash' => hash('sha256', $secret),
            'client' => $validated['client'],
            'machine' => $validated['machine'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'expires_at' => now()->addMinutes((int) config('opal.sessions.link_ttl', 10)),
        ]);

        return response()->json([
            'code' => $link->code,
            'secret' => $secret,
            'expires_at' => $link->expires_at->toIso8601String(),
            'verify_url' => route('link', ['code' => $link->code]),
            'poll_interval' => 2,
        ], 201);
    }

    /**
     * Poll a link.
     *
     * Pending until approved; then the token is returned exactly once.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string']]);

        $link = DeviceLink::findByCode($code);

        abort_if($link === null || ! $link->secretMatches($validated['secret']), 404);
        abort_if($link->isExpired(), 410, 'This link code has expired.');

        if (! $link->isClaimed()) {
            return response()->json(['status' => 'pending']);
        }

        if ($link->isDelivered() || $link->token_plain === null) {
            return response()->json(['status' => 'delivered']);
        }

        $token = $link->token_plain;
        $user = $link->user;

        $link->forceFill(['token_plain' => null, 'delivered_at' => now()])->save();

        return response()->json([
            'status' => 'claimed',
            'token' => $token,
            'user' => ['name' => $user?->name, 'email' => $user?->email],
            'realtime' => Realtime::clientConfig(),
        ]);
    }
}
