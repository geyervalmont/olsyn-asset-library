<?php

namespace App\Http\Controllers\Api;

use App\Library\Studio\DraftStore;
use App\Models\SynthesisRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SynthesisWorkerController
{
    private function authorize(Request $request, SynthesisRun $run, bool $allowComplete = false): void
    {
        abort_unless(hash_equals($run->worker_token, (string) $request->bearerToken()), 401);
        abort_if($run->deadline_at->isPast() || $run->revision->draft->state === 'discarded', 410);
        abort_if($run->terminal() && ! ($allowComplete && $run->status === 'succeeded'), 410);
    }

    public function show(Request $request, SynthesisRun $run): JsonResponse
    {
        $this->authorize($request, $run);
        $document = $run->revision->document;

        return response()->json([
            'schema' => 1, 'run' => $run->uuid,
            'backend' => $run->runtime('backend') ?: 'chord',
            'resolution' => $document['resolution'], 'cleanup' => $document['cleanup'] ?? 0,
            'crop' => $document['crop'] ?? ['x' => 0, 'y' => 0, 'size' => 100],
            'source_sha256' => $document['source']['sha256'],
            'model_url' => Storage::disk($run->runtime('model_disk'))->temporaryUrl($run->runtime('model_path'), $run->deadline_at),
            'model_sha256' => $run->runtime('model_sha256'),
        ]);
    }

    public function source(Request $request, SynthesisRun $run, DraftStore $store): Response
    {
        $this->authorize($request, $run);

        return response($store->bytes($run->revision->document['source']), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    public function progress(Request $request, SynthesisRun $run): JsonResponse
    {
        $this->authorize($request, $run);
        $data = $request->validate(['stage' => 'required|in:loading_models,preparing_photo,cleaning_photo,estimating_material,uploading_maps', 'error' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $run, $data): void {
            $locked = SynthesisRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->authorize($request, $locked);
            $locked->update(['status' => empty($data['error']) ? 'running' : 'failed', 'stage' => $data['stage'], 'heartbeat_at' => now(), 'error' => empty($data['error']) ? null : 'The synthesis worker failed. Your draft is saved.']);
        });

        return response()->json(['ok' => true]);
    }

    public function artifact(Request $request, SynthesisRun $run, string $role, DraftStore $store): JsonResponse
    {
        $this->authorize($request, $run);
        abort_unless(in_array($role, [...DraftStore::MAPS, 'prepared', 'cleaned'], true), 404);
        $request->validate(['image' => 'required|file|mimetypes:image/png|max:32768', 'sha256' => 'required|regex:/^[a-f0-9]{64}$/']);
        $bytes = (string) $request->file('image')?->get();
        abort_unless(hash_equals((string) $request->input('sha256'), hash('sha256', $bytes)), 422);
        $info = @getimagesizefromstring($bytes);
        $resolution = (int) $run->revision->document['resolution'];
        abort_unless($info !== false && $info[2] === IMAGETYPE_PNG && $info[0] === $resolution && $info[1] === $resolution, 422);
        abort_if($role === 'height' && ($info['bits'] ?? 0) < 16, 422, 'Height must retain 16-bit precision.');

        DB::transaction(function () use ($request, $run, $role, $store, $bytes): void {
            $locked = SynthesisRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->authorize($request, $locked);
            $artifacts = $locked->artifacts ?? [];
            if (isset($artifacts[$role])) {
                abort_unless(hash_equals($artifacts[$role]['sha256'], hash('sha256', $bytes)), 409);
            } else {
                $artifacts[$role] = $store->put($bytes, $role);
                $locked->update(['artifacts' => $artifacts]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, SynthesisRun $run): JsonResponse
    {
        $this->authorize($request, $run, true);
        $data = $request->validate(['normal_convention' => 'required|in:opengl', 'model_sha256' => 'required|string|size:64', 'model' => 'required_without:chord_revision|in:chord,rgbx', 'model_revision' => 'required_with:model|string|max:64', 'chord_revision' => 'required_without:model|string|max:64', 'cleanup_revision' => 'nullable|string|max:64', 'seed' => 'nullable|integer|min:0', 'height_method' => 'nullable|in:periodic-normal-integration-relative', 'normal_method' => 'nullable|in:front-facing-planar-camera-space', 'inference_steps' => 'nullable|integer|min:1|max:100', 'inference_seconds' => 'nullable|numeric|min:0', 'seconds' => 'required|numeric|min:0', 'peak_vram_bytes' => 'nullable|integer|min:0']);
        abort_unless(hash_equals((string) $run->runtime('model_sha256'), $data['model_sha256']), 422);
        abort_unless(($data['model'] ?? 'chord') === ($run->runtime('backend') ?: 'chord'), 422);
        DB::transaction(function () use ($request, $run, $data): void {
            $locked = SynthesisRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->authorize($request, $locked, true);
            if ($locked->status === 'succeeded') {
                return;
            }
            abort_unless(array_diff(DraftStore::MAPS, array_keys($locked->artifacts ?? [])) === [], 422, 'The complete five-map set is required.');
            $locked->revision->update(['artifacts' => $locked->artifacts]);
            $locked->update(['status' => 'succeeded', 'stage' => 'complete', 'manifest' => array_merge($locked->manifest ?? [], $data), 'heartbeat_at' => now()]);
            // Deliberately do not move the draft head. Late results belong only
            // to the immutable revision that requested this generation.
        });

        return response()->json(['ok' => true]);
    }
}
