<?php

namespace App\Http\Controllers\Api\Drive;

use App\Models\DriveConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class DriveHeartbeatController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid'], 'machine' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:40'], 'state' => ['required', 'in:connecting,mounted,error,offline'],
            'mount_path' => ['nullable', 'string', 'max:255', 'required_if:state,mounted'],
            'error_code' => ['nullable', 'in:network,sign_in,driver_missing,mount_in_use,access_denied,unknown'],
        ]);
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        abort_if($token === null || $token->tokenable_id !== $request->user()->id || $token->tokenable_type !== $request->user()->getMorphClass(), 403, 'A device token is required.');
        DriveConnection::updateOrCreate(['user_id' => $request->user()->id, 'device_id' => strtolower($data['device_id'])], [
            'token_id' => $token->id, 'machine' => $data['machine'], 'version' => $data['version'] ?? null,
            'state' => $data['state'], 'mount_path' => $data['mount_path'] ?? null,
            'error_code' => $data['state'] === 'error' ? ($data['error_code'] ?? 'unknown') : null, 'last_seen_at' => now(),
        ]);

        return response()->json(['data' => ['ok' => true, 'heartbeat_seconds' => 30]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
