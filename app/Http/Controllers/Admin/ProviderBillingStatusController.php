<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderBillingStatusController extends Controller
{
    public function __construct(protected BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'provider_id' => 'nullable|integer',
            'billing_status' => 'nullable|in:not_configured,exempt,free_trial,active,invoice_due,overdue',
            'provider_status' => 'nullable|in:pending,approved,rejected,suspended',
        ]);

        $rows = $this->service->providerStatuses($validated);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
            ],
        ]);
    }
}
