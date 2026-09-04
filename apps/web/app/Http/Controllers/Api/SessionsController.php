<?php

namespace App\Http\Controllers\Api;

use App\Actions\Clients\TouchClientSession;
use App\Enums\CommandStatus;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class SessionsController
{
    /**
     * My live sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = ClientSession::query()
            ->where('user_id', $request->user()->getKey())
            ->live()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (ClientSession $session): array => $session->toBroadcast() + ['channel' => $session->channel()]);

        return response()->json(['data' => $sessions]);
    }

    /**
     * Announce a running client.
     *
     * Heartbeat it at least every 90 seconds or it is no longer offered
     * commands.
     */
    public function store(Request $request, TouchClientSession $touch): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'exists:platforms,slug'],
            'machine' => ['required', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'document' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);

        $session = new ClientSession([
            'user_id' => $user->getKey(),
            'platform' => $validated['platform'],
            'machine' => $validated['machine'],
            'app_version' => $validated['app_version'] ?? null,
            'document' => $validated['document'] ?? null,
            'token_id' => $token?->getKey(),
        ]);

        $touch->handle($session);

        return response()->json(['data' => ['id' => $session->getKey(), 'channel' => $session->channel()]], 201);
    }

    /**
     * Keep a session live and update its open document.
     */
    public function heartbeat(Request $request, int $session, TouchClientSession $touch): JsonResponse
    {
        $validated = $request->validate(['document' => ['nullable', 'string', 'max:255']]);

        $touch->handle($this->mine($request, $session), array_key_exists('document', $validated) ? ['document' => $validated['document']] : []);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * End a session.
     */
    public function destroy(Request $request, int $session, TouchClientSession $touch): Response
    {
        $touch->end($this->mine($request, $session));

        return response()->noContent();
    }

    /**
     * Commands waiting for a session.
     */
    public function commands(Request $request, int $session): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', 'string']]);
        $status = CommandStatus::tryFrom($validated['status'] ?? 'queued') ?? CommandStatus::Queued;

        $commands = $this->mine($request, $session)->commands()
            ->where('status', $status)
            ->orderBy('id')
            ->get()
            ->map(fn (ClientCommand $command): array => [
                'id' => $command->getKey(),
                'type' => $command->type->value,
                'payload' => $command->payload,
                'status' => $command->status->value,
                'created_at' => $command->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $commands]);
    }

    private function mine(Request $request, int $id): ClientSession
    {
        return ClientSession::query()->whereKey($id)->where('user_id', $request->user()->getKey())->firstOrFail();
    }
}
