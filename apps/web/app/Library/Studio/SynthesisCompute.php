<?php

namespace App\Library\Studio;

use App\Models\SynthesisRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SynthesisCompute
{
    private function broker(): PendingRequest
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $encode((string) json_encode(['iss' => 'material-synthesis', 'sub' => 'material-synthesis', 'iat' => time(), 'exp' => time() + 120]));
        $secret = (string) config('synthesis.broker_secret');
        throw_if($secret === '', RuntimeException::class, 'Compute broker credentials are missing.');
        $jwt = $header.'.'.$payload.'.'.$encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return Http::baseUrl((string) config('synthesis.broker_url'))->withToken($jwt)->acceptJson()->connectTimeout(5)->timeout(35);
    }

    private function kubernetes(): PendingRequest
    {
        $token = @file_get_contents((string) config('synthesis.kubernetes_token_file'));
        throw_unless(is_string($token), RuntimeException::class, 'Worker scheduler credentials are missing.');

        return Http::baseUrl((string) config('synthesis.kubernetes_url'))->withToken(trim($token))->acceptJson()->withOptions(['verify' => config('synthesis.kubernetes_ca')])->connectTimeout(5)->timeout(15);
    }

    private function path(SynthesisRun $run): string
    {
        return '/apis/batch/v1/namespaces/'.config('synthesis.namespace').'/jobs/'.$run->jobName();
    }

    /** @return array<string, mixed> */
    public function allocate(SynthesisRun $run): array
    {
        $profile = $run->runtime('profile');
        /** @var list<array<string, mixed>> $profiles */
        $profiles = $this->broker()->get('/profiles')->throw()->json('profiles');
        $selected = collect($profiles)->firstWhere('name', $profile);
        throw_unless(is_array($selected) && ($selected['provider'] ?? '') === 'aws' && ! empty($selected['gpu_class']), RuntimeException::class, 'Material synthesis requires an AWS GPU profile. On-prem fallback is disabled.');

        // Never automatically retry allocation: an ambiguous timeout could create
        // duplicate paid capacity. The broker expires abandoned leases after 180s.
        $allocation = $this->broker()->post('/allocate', [
            'consumer' => 'material-synthesis', 'profile' => $profile,
            'project_id' => $run->uuid, 'ttl_seconds' => 180,
            'max_duration_seconds' => max(60, (int) now()->diffInSeconds($run->deadline_at, false)),
            'metadata' => ['opal_run' => $run->uuid, 'opal_user_id' => $run->revision->draft->user_id, 'opal_tenant_id' => $run->revision->draft->tenant_id],
        ])->throw()->json();
        throw_unless(is_array($allocation) && ($allocation['provider'] ?? '') === 'aws' && ! empty($allocation['node_selector']), RuntimeException::class, 'Invalid cloud allocation response.');

        return $allocation;
    }

    public function heartbeat(SynthesisRun $run): void
    {
        $this->broker()->post('/allocations/'.$run->allocation['allocation_id'].'/heartbeat')->throw();
    }

    public function launch(SynthesisRun $run): void
    {
        $name = $run->jobName();
        $response = $this->kubernetes()->post('/apis/batch/v1/namespaces/'.config('synthesis.namespace').'/jobs', [
            'apiVersion' => 'batch/v1', 'kind' => 'Job',
            'metadata' => ['name' => $name, 'labels' => ['app' => 'opal-synthesis']],
            'spec' => [
                'backoffLimit' => 0, 'ttlSecondsAfterFinished' => 600,
                'activeDeadlineSeconds' => max(1, (int) now()->diffInSeconds($run->deadline_at, false)),
                'template' => ['metadata' => ['labels' => ['app' => 'opal-synthesis']], 'spec' => [
                    'restartPolicy' => 'Never', 'automountServiceAccountToken' => false,
                    'nodeSelector' => $run->allocation['node_selector'],
                    'tolerations' => $run->allocation['tolerations'] ?? [],
                    'imagePullSecrets' => [['name' => 'ghcr-secret']],
                    'containers' => [[
                        'name' => 'worker', 'image' => $run->runtime('image'),
                        'env' => [
                            ['name' => 'OPAL_URL', 'value' => rtrim((string) config('synthesis.callback_url'), '/')],
                            ['name' => 'OPAL_RUN', 'value' => $run->uuid],
                            ['name' => 'OPAL_TOKEN', 'value' => $run->worker_token],
                            ['name' => 'HF_HUB_OFFLINE', 'value' => '1'],
                            ['name' => 'TRANSFORMERS_OFFLINE', 'value' => '1'],
                        ],
                        'resources' => ['requests' => ['cpu' => '1', 'memory' => '8Gi', 'nvidia.com/gpu' => '1'], 'limits' => ['cpu' => '4', 'memory' => '24Gi', 'nvidia.com/gpu' => '1']],
                        'securityContext' => ['allowPrivilegeEscalation' => false, 'capabilities' => ['drop' => ['ALL']]],
                        'volumeMounts' => [['name' => 'shm', 'mountPath' => '/dev/shm']],
                    ]],
                    'volumes' => [['name' => 'shm', 'emptyDir' => ['medium' => 'Memory', 'sizeLimit' => '2Gi']]],
                ]],
            ],
        ]);
        if ($response->status() !== 409) {
            $response->throw();
        }
    }

    /** @return array<string, mixed> */
    public function status(SynthesisRun $run): array
    {
        $response = $this->kubernetes()->get($this->path($run));

        return $response->status() === 404 ? [] : $response->throw()->json('status', []);
    }

    public function release(SynthesisRun $run): void
    {
        // Foreground deletion ensures the GPU process is gone before releasing its slot.
        $response = $this->kubernetes()->delete($this->path($run), ['propagationPolicy' => 'Foreground', 'gracePeriodSeconds' => 0]);
        if ($response->status() !== 404) {
            $response->throw();
            if ($this->kubernetes()->get($this->path($run))->status() !== 404) {
                return; // The next reconciliation finishes releasing the lease.
            }
        }
        if ($run->allocation !== null) {
            $response = $this->broker()->post('/allocations/'.$run->allocation['allocation_id'].'/release', ['end_reason' => 'released']);
            if (! in_array($response->status(), [404, 410], true)) {
                $response->throw();
            }
        }
        $run->update(['released_at' => now()]);
    }
}
