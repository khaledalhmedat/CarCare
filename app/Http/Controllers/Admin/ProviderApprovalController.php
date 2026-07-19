<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ProviderApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderApprovalController extends Controller
{
    public function __construct(protected ProviderApprovalService $service) {}

    public function index(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,suspended,all',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $providers = $this->service->list($type, $request->only(['status', 'per_page']));
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'data' => $resourceClass::collection($providers),
            'meta' => [
                'total' => $providers->total(),
                'per_page' => $providers->perPage(),
                'current_page' => $providers->currentPage(),
            ],
        ]);
    }

    public function show(string $type, int $id): JsonResponse
    {
        try {
            $provider = $this->service->find($type, $id);
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'data' => new $resourceClass($provider),
        ]);
    }

    public function approve(string $type, int $id): JsonResponse
    {
        try {
            $provider = $this->service->approve($type, $id);
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم قبول مزود الخدمة بنجاح',
            'data' => new $resourceClass($provider),
        ]);
    }

    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $provider = $this->service->reject($type, $id, $validated['rejection_reason']);
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم رفض مزود الخدمة',
            'data' => new $resourceClass($provider),
        ]);
    }

    public function suspend(string $type, int $id): JsonResponse
    {
        try {
            $provider = $this->service->suspend($type, $id);
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إيقاف مزود الخدمة',
            'data' => new $resourceClass($provider),
        ]);
    }

    public function reactivate(string $type, int $id): JsonResponse
    {
        try {
            $provider = $this->service->reactivate($type, $id);
            $resourceClass = $this->service->resourceClass($type);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة تفعيل مزود الخدمة',
            'data' => new $resourceClass($provider),
        ]);
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        $status = $e instanceof \InvalidArgumentException ? 422 : 404;

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $status);
    }
}
