<?php

namespace App\Http\Controllers\MaintenanceRequest;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceRequest\StoreMaintenanceRequest;
use App\Http\Requests\MaintenanceRequest\CancelMaintenanceRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Services\MaintenanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    public function __construct(
        protected MaintenanceRequestService $requestService
    ) {}

    //عرض طلبات الصيانة للمستخدم
    
    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->get('status');
            $requests = $this->requestService->getUserRequests(
                $request->user(),
                $status
            );

            return response()->json([
                'success' => true,
                'data' => MaintenanceRequestResource::collection($requests),
                'meta' => [
                    'total' => $requests->total(),
                    'per_page' => $requests->perPage(),
                    'current_page' => $requests->currentPage(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //عرض الطلبات المعلقة 
    public function pending(Request $request): JsonResponse
    {
        try {
            $requests = $this->requestService->getPendingRequests($request->user());

            return response()->json([
                'success' => true,
                'data' => MaintenanceRequestResource::collection($requests)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //عرض الطلبات المقبولة
    public function accepted(Request $request): JsonResponse
    {
        try {
            $requests = $this->requestService->getAcceptedRequests($request->user());

            return response()->json([
                'success' => true,
                'data' => MaintenanceRequestResource::collection($requests)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //عرض الطلبات المنجزة
    public function completed(Request $request): JsonResponse
    {
        try {
            $requests = $this->requestService->getCompletedRequests($request->user());

            return response()->json([
                'success' => true,
                'data' => MaintenanceRequestResource::collection($requests)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //عرض طلب محدد
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $maintenanceRequest = $this->requestService->getRequest($id, $request->user());

            return response()->json([
                'success' => true,
                'data' => new MaintenanceRequestResource($maintenanceRequest)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //انشاء طلب جديد
    public function store(StoreMaintenanceRequest $request): JsonResponse
    {
        try {
            $maintenanceRequest = $this->requestService->createRequest(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء طلب الصيانة بنجاح',
                'data' => new MaintenanceRequestResource($maintenanceRequest)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //تعديل طلب
    public function update(StoreMaintenanceRequest $request, int $id): JsonResponse
    {
        try {
            $maintenanceRequest = $this->requestService->updateRequest(
                $id,
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث طلب الصيانة بنجاح',
                'data' => new MaintenanceRequestResource($maintenanceRequest)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //الغاء طلب
    public function cancel(CancelMaintenanceRequest $request, int $id): JsonResponse
    {
        try {
            $this->requestService->cancelRequest(
                $id,
                $request->user(),
                $request->cancellation_reason
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إلغاء طلب الصيانة بنجاح'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //حذف طلب
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->requestService->deleteRequest($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'تم حذف طلب الصيانة بنجاح'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //قبول عرض سعر
    public function acceptQuotation(Request $request, int $id, int $quotationId): JsonResponse
    {
        try {
            $this->requestService->acceptQuotation(
                $id,
                $quotationId,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم قبول العرض بنجاح'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}