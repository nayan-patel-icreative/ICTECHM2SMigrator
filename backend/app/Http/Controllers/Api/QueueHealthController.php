<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QueueHealthService;
use Illuminate\Http\Request;

class QueueHealthController extends Controller
{
    public function show(Request $request, QueueHealthService $queueHealth)
    {
        $online = $queueHealth->isWorkerOnlineCached();

        return response()->json([
            'worker_online' => $online,
        ]);
    }
}
