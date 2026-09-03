<?php

namespace App\Http\Controllers\Api;

use App\Actions\Platforms\AssignPlatformIdentity;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentitiesController
{
    /**
     * Record what a variant is called on a platform.
     *
     * Called by client extensions after they apply a variant, so a Revit
     * material id or name resolves back to the library from then on.
     */
    public function store(Request $request, string $code, AssignPlatformIdentity $assign): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isSuperAdmin() || $user->tenants()->exists(), 403, 'A workspace membership is required to register identities.');

        $validated = $request->validate([
            'platform' => ['required', 'string', 'exists:platforms,slug'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'external_name' => ['nullable', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        $variant = Variant::resolveCode($code);

        abort_if($variant === null || ! $variant->material->isVisibleTo($user), 404);

        $identity = $assign->handle(
            $variant,
            $validated['platform'],
            $validated['external_id'] ?? null,
            $validated['external_name'] ?? null,
            $validated['payload'] ?? null,
        );

        return response()->json(['data' => [
            'variant' => $variant->code,
            'platform' => $identity->platform->slug,
            'external_id' => $identity->external_id,
            'external_name' => $identity->external_name,
        ]], 201);
    }
}
