<?php

return [
    'enabled' => env('OLSYN_ACCESS_ENABLED', false),
    'issuer' => env('OLSYN_OIDC_ISSUER', 'https://auth.olsyn.com'),
    'client_id' => env('OLSYN_OIDC_CLIENT_ID', ''),
    'client_secret' => env('OLSYN_OIDC_CLIENT_SECRET', ''),
    'redirect_uri' => env('OLSYN_OIDC_REDIRECT_URI', 'https://opal.olsyn.com/auth/callback'),
    'decision_url' => env('OLSYN_ACCESS_URL', 'https://app.olsyn.com/api/service-access/v1/opal/decision'),
    'token' => env('OLSYN_ACCESS_TOKEN', ''),
];
