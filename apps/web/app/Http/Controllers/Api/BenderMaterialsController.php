<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Library\Studio\DraftStore;
use App\Models\MaterialCommission;
use App\Models\StudioDraft;
use App\Models\Tenant;
use App\Services\OlsynAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BenderMaterialsController extends Controller
{
    public const ROLES = ['base_color', 'normal', 'roughness'];

    private function authorizeBridge(Request $request, bool $write = false): Tenant
    {
        $user = $request->user();
        abort_unless($user->workos_id && $user->hasVerifiedEmail() && $user->currentAccessToken()->can('bender:materials'), 403);
        $tenant = $user->resolveCurrentTenant();
        abort_unless($tenant && $user->canAccessTenant($tenant), 403);
        $tenant->makeCurrent();
        $user->unsetRelation('roles');
        $permission = $write ? 'materials.contribute' : 'materials.view';
        abort_unless(app(OlsynAccess::class)->allows($user, $permission) && $user->can($permission), 403);

        return $tenant;
    }

    public function identity(Request $request): JsonResponse
    {
        $tenant = $this->authorizeBridge($request);

        return response()->json(['contract' => 'bender-materials/1', 'subject' => $request->user()->workos_id, 'tenant_id' => (string) $tenant->id, 'name' => $request->user()->name, 'can_commission' => $request->user()->can('materials.contribute')])->header('Cache-Control', 'private, no-store');
    }

    public function search(Request $request, MaterialsController $materials): JsonResponse
    {
        $this->authorizeBridge($request);

        return $materials->index($request)->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->authorizeBridge($request, true);
        $data = $request->validate(['idempotency_key' => 'required|string|max:100', 'brief' => 'required|string|min:3|max:8000', 'bender_job_id' => 'required|uuid', 'trace_id' => 'required|string|regex:/^[a-f0-9]{32}$/']);
        $hash = hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $data['brief']))));
        $commission = DB::transaction(function () use ($request, $tenant, $data, $hash): MaterialCommission {
            // Serialize decisions per person, including concurrent duplicate submissions.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = MaterialCommission::where('user_id', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->tenant_id === $tenant->id && $existing->request_hash === $hash && $existing->bender_job_id === $data['bender_job_id'] && $existing->trace_id === $data['trace_id'], 409, 'Idempotency key has different inputs.');

                return $existing;
            }
            $reusable = MaterialCommission::where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->where('request_hash', $hash)->where('status', 'ready')->whereHas('draft', fn ($q) => $q->whereIn('state', ['active', 'promoted']))->latest()->first();
            abort_if(! $reusable && MaterialCommission::where('user_id', $request->user()->id)->whereDate('created_at', today())->count() >= 30, 429, 'Daily material commission limit reached.');

            return MaterialCommission::create([...$data, 'id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'request_hash' => $hash, 'status' => $reusable ? 'ready' : 'requested', 'artifacts' => $reusable?->artifacts, 'studio_draft_id' => $reusable?->studio_draft_id, 'provenance' => $reusable ? [...($reusable->provenance ?? []), 'reused_commission_id' => $reusable->id] : null]);
        });

        return response()->json($this->payload($commission), 201)->header('Cache-Control', 'private, no-store');
    }

    private function owned(Request $request, string $id, bool $write = false): MaterialCommission
    {
        $tenant = $this->authorizeBridge($request, $write);

        $commission = MaterialCommission::where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->findOrFail($id);
        abort_if($commission->status === 'ready' && ! in_array($commission->draft?->state, ['active', 'promoted'], true), 410, 'This material draft is no longer available.');

        return $commission;
    }

    /** @return array<string, mixed> */
    private function payload(MaterialCommission $commission): array
    {
        return ['id' => $commission->id, 'status' => $commission->status, 'decision' => $commission->status === 'ready' ? 'reuse' : 'commission', 'request_hash' => $commission->request_hash, 'review_state' => 'private_draft', 'artifacts' => collect($commission->artifacts ?? [])->map(fn ($asset, $role) => ['role' => $role, 'sha256' => $asset['sha256'], 'bytes' => $asset['bytes'], 'width' => $asset['width'], 'height' => $asset['height']])->values()->all(), 'draft_url' => $commission->draft ? route('materials.studio.photos', ['draft' => $commission->draft->uuid]) : null, 'provenance' => $commission->provenance];
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->payload($this->owned($request, $id)))->header('Cache-Control', 'private, no-store');
    }

    public function artifact(Request $request, string $id, string $role): Response
    {
        abort_unless(in_array($role, self::ROLES, true), 404);
        $commission = $this->owned($request, $id, $request->isMethod('post'));
        if ($request->isMethod('get')) {
            abort_unless($commission->status === 'ready' && isset($commission->artifacts[$role]), 404);

            return response(app(DraftStore::class)->bytes($commission->artifacts[$role]))->header('Content-Type', 'image/png')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        }
        $data = $request->validate(['data' => 'required|string|max:5592408', 'sha256' => 'required|string|regex:/^[a-f0-9]{64}$/']);
        $bytes = base64_decode($data['data'], true);
        abort_unless(is_string($bytes) && strlen($bytes) <= 4 * 1024 * 1024 && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && hash_equals($data['sha256'], hash('sha256', $bytes)), 422, 'Invalid PNG or hash.');
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] >= 1 && $size[1] >= 1 && $size[0] <= 2048 && $size[1] <= 2048, 422, 'Maps must be at most 2048 pixels.');
        DB::transaction(function () use ($commission, $role, $bytes, $data): void {
            $locked = MaterialCommission::lockForUpdate()->findOrFail($commission->id);
            $existing = $locked->artifacts[$role] ?? null;
            if ($existing) {
                abort_unless(hash_equals($existing['sha256'], $data['sha256']), 409, 'Map already uploaded with different content.');

                return;
            }
            abort_unless($locked->status === 'requested', 409);
            $locked->update(['artifacts' => [...($locked->artifacts ?? []), $role => app(DraftStore::class)->put($bytes, $role)]]);
        });

        return response()->json(['status' => 'stored', 'sha256' => $data['sha256']]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $commission = $this->owned($request, $id, true);
        $data = $request->validate(['width_mm' => 'present|nullable|numeric|gt:0|max:100000', 'height_mm' => 'present|nullable|numeric|gt:0|max:100000', 'normal_convention' => 'required|in:opengl', 'bender_artifact_ids' => 'required|array|size:3', 'bender_artifact_ids.*' => 'required|uuid', 'source_job_id' => 'required|uuid']);
        DB::transaction(function () use ($commission, $data): void {
            $locked = MaterialCommission::lockForUpdate()->findOrFail($commission->id);
            if ($locked->status === 'ready') {
                abort_unless($locked->provenance == $data, 409, 'Completion metadata differs from the accepted material.');

                return;
            }
            abort_unless(array_diff(self::ROLES, array_keys($locked->artifacts ?? [])) === [], 422, 'Upload all three maps first.');
            $dimensions = collect($locked->artifacts)->map(fn ($a) => $a['width'].'x'.$a['height'])->unique();
            abort_unless($dimensions->count() === 1, 422, 'Map dimensions must agree.');
            $draft = StudioDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => $locked->user_id, 'tenant_id' => $locked->tenant_id, 'name' => Str::limit($locked->brief, 120, ''), 'state' => 'active']);
            $revision = $draft->revisions()->create(['document' => ['schema' => 1, 'source' => $locked->artifacts['base_color'], 'width_mm' => $data['width_mm'], 'height_mm' => $data['height_mm'], 'resolution' => $locked->artifacts['base_color']['width'], 'normal_convention' => $data['normal_convention'], 'bender_commission_id' => $locked->id, 'bender_trace_id' => $locked->trace_id, 'provenance' => $data], 'artifacts' => $locked->artifacts]);
            $draft->update(['head_id' => $revision->id]);
            $locked->update(['status' => 'ready', 'studio_draft_id' => $draft->id, 'provenance' => $data]);
        });

        return response()->json($this->payload($commission->fresh()));
    }
}
