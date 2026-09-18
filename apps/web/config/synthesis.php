<?php

return [
    'enabled' => (bool) env('OPAL_SYNTHESIS_ENABLED', false),
    'backend' => env('OPAL_SYNTHESIS_BACKEND', 'chord'),
    'cleanup_enabled' => (bool) env('OPAL_SYNTHESIS_CLEANUP_ENABLED', false),
    'disk' => env('OPAL_SYNTHESIS_DISK', env('OPAL_FILES_DISK', 'local')),
    'prefix' => 'opal/studio-drafts',
    'broker_url' => env('OPAL_SYNTHESIS_BROKER_URL', 'http://olsyn-broker.streaming.svc.cluster.local'),
    'broker_secret' => env('OPAL_SYNTHESIS_BROKER_SECRET', ''),
    'profile' => env('OPAL_SYNTHESIS_PROFILE', 'burst-l40s-solo'),
    'image' => env('OPAL_SYNTHESIS_IMAGE', ''),
    'callback_url' => env('OPAL_SYNTHESIS_CALLBACK_URL', env('APP_URL')),
    'namespace' => env('OPAL_SYNTHESIS_NAMESPACE', 'opal-synthesis'),
    'kubernetes_url' => env('OPAL_SYNTHESIS_KUBERNETES_URL', 'https://kubernetes.default.svc'),
    'kubernetes_token_file' => '/var/run/secrets/kubernetes.io/serviceaccount/token',
    'kubernetes_ca' => '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt',
    'model_disk' => env('OPAL_SYNTHESIS_MODEL_DISK', 's3'),
    'model_path' => env('OPAL_SYNTHESIS_MODEL_PATH', ''),
    'model_sha256' => env('OPAL_SYNTHESIS_MODEL_SHA256', ''),
    'max_seconds' => 1800,
    'max_concurrent' => 1,
    'daily_runs' => 30,
];
