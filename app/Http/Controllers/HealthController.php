<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Public, lightweight liveness/health check.
     */
    public function index(): JsonResponse
    {
        $databaseStatus = 'connected';

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            $databaseStatus = 'disconnected';
        }

        return response()->json([
            'success' => true,
            'status' => 'ok',
            'app' => config('app.name'),
            'database' => $databaseStatus,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
