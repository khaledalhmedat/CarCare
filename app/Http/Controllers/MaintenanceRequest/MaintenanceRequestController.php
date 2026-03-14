<?php

namespace App\Http\Controllers\MaintenanceRequest;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceRequest\StoreMaintenanceRequest;
use App\Http\Requests\MaintenanceRequest\UpdateMaintenanceRequest;
use App\Http\Requests\MaintenanceRequest\CancelMaintenanceRequest;
use App\Http\Requests\User\AcceptQuotationRequest;
use App\Http\Requests\User\RateServiceRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Http\Resources\UserQuotationResource;
use App\Services\MaintenanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    public function __construct(
        protected MaintenanceRequestService $requestService
    ) {}


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


    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $maintenanceRequest = $this->requestService->getRequest($id, $request->user());

            return response()->json([
                'success' => true,
                'data' => new MaintenanceRequestResource(
                    $maintenanceRequest->load(['vehicle', 'photos', 'quotations.technician'])
                )
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }


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


    public function update(UpdateMaintenanceRequest $request, int $id): JsonResponse
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


    public function quotations(Request $request, int $id): JsonResponse
    {
        try {
            $quotations = $this->requestService->getRequestQuotations(
                $request->user(),
                $id
            );

            return response()->json([
                'success' => true,
                'data' => UserQuotationResource::collection($quotations)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function acceptQuotation(AcceptQuotationRequest $request, int $id, int $quotationId): JsonResponse
    {
        try {
            $result = $this->requestService->acceptQuotationWithSchedule(
                $id,
                $quotationId,
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم قبول عرض السعر وجدولة الموعد بنجاح',
                'data' => [
                    'quotation' => new UserQuotationResource($result['quotation']),
                    'service_job' => $result['service_job'],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function acceptQuotationQuick(Request $request, int $id, int $quotationId): JsonResponse
    {
        try {
            $this->requestService->acceptQuotation(
                $id,
                $quotationId,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم قبول عرض السعر بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function rejectQuotation(Request $request, int $quotationId): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500']
        ]);

        try {
            $this->requestService->rejectQuotation(
                $request->user(),
                $quotationId,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => 'تم رفض عرض السعر'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function acceptedQuotation(Request $request, int $id): JsonResponse
    {
        try {
            $quotation = $this->requestService->getAcceptedQuotation(
                $request->user(),
                $id
            );

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يوجد عرض مقبول لهذا الطلب'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new UserQuotationResource($quotation)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function rateService(RateServiceRequest $request, int $jobId): JsonResponse
    {
        try {
            $rating = $this->requestService->rateService(
                $request->user(),
                $jobId,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تقييم الخدمة بنجاح',
                'data' => [
                    'id' => $rating->id,
                    'rating' => $rating->rating,
                    'review' => $rating->review,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function reopenRequest(Request $request, int $id): JsonResponse
    {
        try {
            $this->requestService->reopenRequest($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'تم إعادة فتح الطلب بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function statistics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $stats = [
                'total_requests' => $user->maintenanceRequests()->count(),
                'pending_requests' => $user->maintenanceRequests()->whereIn('status', ['pending', 'quoted'])->count(),
                'accepted_requests' => $user->maintenanceRequests()->whereIn('status', ['quotation_accepted', 'in_progress'])->count(),
                'completed_requests' => $user->maintenanceRequests()->where('status', 'completed')->count(),
                'cancelled_requests' => $user->maintenanceRequests()->where('status', 'cancelled')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
