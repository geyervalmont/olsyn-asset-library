<?php

namespace App\Http\Controllers\Api;

use App\Support\Realtime;
use Illuminate\Http\JsonResponse;

class RealtimeController
{
    /**
     * Where to open a websocket for live commands.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => Realtime::clientConfig()]);
    }
}
