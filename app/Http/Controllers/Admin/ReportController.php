<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Services\Admin\BillingService;
use App\Services\Admin\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(protected ReportService $service) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'status' => 'nullable|string|max:50',
        ]);

        return $this->ok($this->service->overview($validated));
    }

    public function operations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'operation_type' => 'nullable|in:maintenance,sos,fuel,car_wash,spare_parts',
            'status' => 'nullable|string|max:50',
            'group_by' => 'nullable|in:day,month,year',
        ]);

        return $this->ok($this->service->operations($validated));
    }

    public function providers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'provider_status' => 'nullable|in:pending,approved,rejected,suspended',
            'billing_status' => 'nullable|in:not_configured,exempt,free_trial,active,invoice_due,overdue',
        ]);

        return $this->ok($this->service->providers($validated));
    }

    public function financial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'group_by' => 'nullable|in:day,month,year',
        ]);

        return $this->ok($this->service->financial($validated));
    }

    public function billing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'status' => 'nullable|in:draft,issued,paid,overdue,cancelled',
        ]);

        return $this->ok($this->service->billing($validated));
    }

    public function advertisements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'placement' => ['nullable', Rule::in(Advertisement::PLACEMENTS)],
            'is_active' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->advertisements($validated));
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
