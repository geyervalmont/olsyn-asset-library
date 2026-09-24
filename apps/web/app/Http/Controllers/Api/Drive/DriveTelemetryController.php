<?php

namespace App\Http\Controllers\Api\Drive;

use App\Models\DriveTelemetryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

final class DriveTelemetryController
{
    public const CODES = ['mount_in_use', 'driver_mount', 'driver_missing', 'credentials', 'settings', 'sign_in_timeout', 'timeout', 'sign_in', 'access_denied', 'proxy', 'dns', 'tls', 'network', 'rate_limited', 'server', 'http_response', 'disk_full', 'local_permissions', 'invalid_response', 'content_integrity', 'local_storage', 'browser', 'unknown'];

    public function __invoke(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 32768, 413);
        $token = PersonalAccessToken::findToken((string) $request->bearerToken());
        abort_unless($token !== null && $token->tokenable_id === $request->user()->id
            && $token->tokenable_type === $request->user()->getMorphClass(), 403);
        // Reject unknown properties instead of retaining arbitrary diagnostic text.
        validator(['payload' => $request->all()], ['payload' => 'array:device_id,events'])->validate();
        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required', 'array:event_id,kind,stage,code,version,occurred_at,details'],
            'events.*.event_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'events.*.kind' => ['required', Rule::in(['error', 'ready'])],
            'events.*.stage' => ['required', Rule::in(['Startup', 'Settings', 'SignIn', 'Browser', 'Credentials', 'Account', 'Heartbeat', 'Library', 'LocalStorage', 'Driver', 'Mount', 'Ready', 'FileRead', 'Uploads', 'Unmount'])],
            'events.*.code' => ['nullable', 'required_if:events.*.kind,error', Rule::in(self::CODES)],
            'events.*.version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(\.\d+)?$/D'],
            'events.*.occurred_at' => ['required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'events.*.details' => ['required', 'array:os_version,hresult,http_status,native_error,driver_status,elapsed_ms'],
            'events.*.details.os_version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(\.\d+)?$/D'],
            'events.*.details.hresult' => ['nullable', 'regex:/^0x[0-9A-F]{8}$/D'],
            'events.*.details.http_status' => ['nullable', 'integer', 'between:100,599'],
            'events.*.details.native_error' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            'events.*.details.driver_status' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            'events.*.details.elapsed_ms' => ['nullable', 'integer', 'between:0,86400000'],
        ]);
        $accepted = [];
        foreach ($data['events'] as $event) {
            abort_if($event['kind'] === 'ready' && ($event['stage'] !== 'Ready' || ($event['code'] ?? null) !== null), 422);
        }
        foreach ($data['events'] as $event) {
            $row = DriveTelemetryEvent::firstOrCreate([
                'user_id' => $request->user()->id, 'event_id' => strtolower($event['event_id']),
            ], [
                'device_id' => strtolower($data['device_id']), 'kind' => $event['kind'], 'stage' => $event['stage'],
                'code' => $event['code'] ?? null, 'version' => $event['version'],
                'occurred_at' => $event['occurred_at'], 'received_at' => now(), 'details' => $event['details'],
            ]);
            if ($row->wasRecentlyCreated && $row->kind === 'error') {
                Log::warning('drive.client_error', [
                    'event_id' => $row->event_id, 'user_id' => $row->user_id, 'device_id' => $row->device_id,
                    'stage' => $row->stage, 'code' => $row->code, 'version' => $row->version, ...$row->details,
                ]);
            }
            $accepted[] = $row->event_id;
        }

        return response()->json(['data' => ['accepted' => $accepted]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
