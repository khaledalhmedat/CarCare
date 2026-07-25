<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $service) {}

    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->summary(),
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:day,month,year',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'group_by' => 'nullable|in:day,month,year',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->operations($validated),
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->revenue($validated),
        ]);
    }
}
