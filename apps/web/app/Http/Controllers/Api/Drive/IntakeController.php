<?php

namespace App\Http\Controllers\Api\Drive;

use App\Actions\Drives\ManageIntake;
use App\Models\DriveIntakeFile;
use App\Models\DriveIntakeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class IntakeController
{
    public function index(Request $request): JsonResponse
    {
        return $this->json(['data' => DriveIntakeSession::query()->where('user_id', $request->user()->id)->visibleTo($request->user())
            ->with('files')->latest()->limit(50)->get()->map(fn ($session) => $this->payload($session))]);
    }

    public function store(Request $request, ManageIntake $intake): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return $this->json(['data' => $this->payload($intake->create($request->user(), $data['name']))], 201);
    }

    public function inbox(Request $request, ManageIntake $intake): JsonResponse
    {
        return $this->json(['data' => $this->payload($intake->inbox($request->user()))]);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return $this->json(['data' => $this->payload($this->owned($request, $session))]);
    }

    public function reserve(Request $request, string $session, ManageIntake $intake): JsonResponse
    {
        $owned = $this->owned($request, $session);
        $data = $request->validate(['path' => ['required', 'string', 'max:512'], 'bytes' => ['required', 'integer', 'min:1', 'max:'.config('opal.drive.intake_file_bytes')], 'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $file = $intake->reserve($owned, $data['path'], (int) $data['bytes'], $data['sha256']);

        return $this->json(['data' => $this->filePayload($owned, $file)], 201);
    }

    public function upload(Request $request, string $session, string $file, ManageIntake $intake): Response
    {
        $owned = $this->owned($request, $session);
        $entry = $owned->files()->where('uuid', $file)->firstOrFail();
        abort_if($request->headers->has('Content-Length') && (int) $request->header('Content-Length') !== $entry->bytes, 422, 'Content-Length must match the reserved file.');
        $input = $request->getContent(true);
        try {
            $intake->upload($owned, $entry, $input);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, string $session, ManageIntake $intake): JsonResponse
    {
        return $this->json(['data' => $this->payload($intake->submit($this->owned($request, $session)))], 202);
    }

    public function destroy(Request $request, string $session, ManageIntake $intake): Response
    {
        $intake->cancel($this->owned($request, $session));

        return response()->noContent();
    }

    private function owned(Request $request, string $uuid): DriveIntakeSession
    {
        return DriveIntakeSession::query()->where('uuid', $uuid)->where('user_id', $request->user()->id)->visibleTo($request->user())->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(DriveIntakeSession $session): array
    {
        return ['id' => $session->uuid, 'name' => $session->name,
            'status' => $session->status === 'open' && ! $session->acceptsUploads() ? 'expired' : $session->status,
            'path' => $session->drivePath(), 'writable' => $session->acceptsUploads() && request()->user()->tokenCan('drive:write'),
            'expires_at' => $session->expires_at?->toIso8601String(), 'processing_enabled' => false,
            'files' => $session->files->map(fn ($file) => $this->filePayload($session, $file))];
    }

    /** @return array<string, mixed> */
    private function filePayload(DriveIntakeSession $session, DriveIntakeFile $file): array
    {
        return ['id' => $file->uuid, 'path' => $file->path, 'bytes' => $file->bytes, 'sha256' => $file->sha256,
            'uploaded' => $file->uploaded_at !== null,
            'upload_url' => route('api.drive.intake.upload', ['session' => $session->uuid, 'file' => $file->uuid], false)];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
