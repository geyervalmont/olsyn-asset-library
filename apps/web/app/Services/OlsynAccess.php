<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OlsynAccess
{
    /**
     * @param  array{workos_id?: string, email?: string}  $identity
     * @return array<string, mixed>
     */
    public function decision(array $identity): array
    {
        abort_unless(strlen(config('olsyn_access.token')) >= 32, 503, 'Service access is not configured.');
        $key = 'olsyn-access:v1:'.hash('sha256', config('olsyn_access.decision_url').json_encode($identity));
        try {
            return Cache::remember($key, 15, function () use ($identity): array {
                $response = Http::withToken(config('olsyn_access.token'))->acceptJson()->timeout(3)
                    ->post(config('olsyn_access.decision_url'), $identity);
                if (! $response->successful() || $response->json('service') !== 'opal'
                    || ! is_bool($response->json('allowed')) || ! is_array($response->json('permissions'))) {
                    throw new \RuntimeException('Invalid service access response');
                }

                return $response->json();
            });
        } catch (Throwable) {
            abort(503, 'Service access is temporarily unavailable. Please try again.');
        }
    }

    /** @return list<string> */
    public function permissions(User $user): array
    {
        if (! $user->hasVerifiedEmail()) {
            return [];
        }
        $request = request();
        $key = 'olsyn.permissions.'.$user->id;
        if (! $request->attributes->has($key)) {
            // Password/passkey recovery uses the existing locally verified account.
            // A WorkOS-linked account remains the same identity regardless of login method.
            $identity = $user->workos_id ? ['workos_id' => $user->workos_id] : ['email' => $user->email];
            $decision = $this->decision($identity);
            $request->attributes->set($key, $decision['allowed'] ? $decision['permissions'] : []);
        }

        return $request->attributes->get($key);
    }

    public function allows(User $user, string $permission): bool
    {
        return ! config('olsyn_access.enabled') || in_array($permission, $this->permissions($user), true);
    }
}
