<?php

namespace App\Http\Controllers\Api\Drive;

use App\Library\Drives\DriveNamespace;
use App\Library\Drives\HttpFileResponse;
use App\Models\DriveIntakeSession;
use App\Models\Material;
use App\Models\Package;
use App\Models\PackageDerivative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PersonalDriveController
{
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'contract' => 'opal-drive/1', 'account' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
            'label' => 'OPAL Materials', 'suggested_mount' => 'O:\\',
            'manifest_url' => route('api.drive.manifest', absolute: false),
            'intake_url' => route('api.drive.intake.index', absolute: false),
            'heartbeat_url' => route('api.drive.heartbeat', absolute: false),
            'refresh_seconds' => 30, 'heartbeat_seconds' => 30,
            'capabilities' => ['read' => true, 'byte_ranges' => true, 'intake' => $user->can('materials.contribute') && $user->tokenCan('drive:write'),
                'ingestion_processing' => false, 'windows_client_available' => config('opal.drive.windows_client_available')],
            'limits' => ['upload_bytes' => config('opal.drive.intake_file_bytes'), 'batch_bytes' => config('opal.drive.intake_batch_bytes'), 'batch_files' => config('opal.drive.intake_max_files')],
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function manifest(Request $request, DriveNamespace $namespace): JsonResponse|Response
    {
        $files = array_map(fn ($entry): array => [
            'path' => $entry['path'], 'bytes' => $entry['object']['size'], 'sha256' => $entry['sha256'],
            'content_url' => route('api.drive.files', ['derivative' => $entry['derivative_uuid'], 'file' => $entry['file_id']], false),
            'material_uuid' => $entry['material_uuid'], 'variant_uuid' => $entry['variant_uuid'], 'material_version' => $entry['material_version'],
            'derivative_uuid' => $entry['derivative_uuid'], 'target' => $entry['target'], 'quality' => $entry['quality'], 'role' => $entry['role'],
        ], $namespace->entriesForUser($request->user()));
        $files = array_merge($files, $namespace->canonicalEntriesForUser($request->user()));
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $incoming = $request->user()->can('materials.contribute') && $request->user()->tokenCan('drive:write')
            ? DriveIntakeSession::query()->where('user_id', $request->user()->id)->open()->orderBy('uuid')->get()->map(fn ($session): array => [
                'id' => $session->uuid, 'path' => '/Incoming/'.$session->uuid, 'label' => $session->name, 'writable' => true,
                'expires_at' => $session->expires_at->toIso8601String(),
                'session_url' => route('api.drive.intake.show', ['session' => $session->uuid], false),
            ])->all() : [];
        $data = ['contract' => 'opal-drive/1', 'files' => $files, 'incoming' => $incoming, 'library_writable' => false];
        $etag = '"'.hash('sha256', $request->user()->id.json_encode($data, JSON_THROW_ON_ERROR)).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-store'];
        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return response()->json($data, headers: $headers);
    }

    public function file(Request $request, string $derivative, int $file, HttpFileResponse $stream): Response
    {
        $projection = PackageDerivative::query()->where('uuid', $derivative)
            ->whereHas('package.versions', fn ($query) => $query->whereNotNull('published_at'))
            ->whereHas('package.variant', fn ($query) => $query->whereIn('material_id', Material::query()->visibleTo($request->user())->select('id')))
            ->with('package')->firstOrFail();
        abort_unless(hash_equals($projection->package->sha256, $projection->source_sha256), 404);
        $entry = $projection->derivativeFiles()->where('file_id', $file)->with('file')->firstOrFail();

        return $stream->send($request, $entry->file);
    }

    public function package(Request $request, int $package, HttpFileResponse $stream): Response
    {
        $package = Package::query()->whereKey($package)->whereHas('versions', fn ($query) => $query->whereNotNull('published_at'))
            ->whereHas('variant', fn ($query) => $query->whereIn('material_id', Material::query()->visibleTo($request->user())->select('id')))->firstOrFail();

        return $stream->sendPackage($request, $package);
    }
}
