<?php

namespace App\Http\Controllers\Technician;

use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\StoreQuotationRequest;
use App\Http\Requests\Technician\UpdateJobStatusRequest;
use App\Http\Resources\TechnicianMaintenanceRequestResource;
use App\Http\Resources\TechnicianQuotationResource;
use App\Services\TechnicianMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Quotation;
use App\Models\ServiceJob;



class TechnicianMaintenanceController extends Controller
{
    public function __construct(
        protected TechnicianMaintenanceService $technicianService
    ) {}

    //عرض طلبات الصيانة المتاحة
    public function availableRequests(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['city', 'priority', 'per_page']);
            $requests = $this->technicianService->getAvailableRequests(
                $request->user(),
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => TechnicianMaintenanceRequestResource::collection($requests),
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
            ], 400);
        }
    }

    //عرض تفاصيل طلب محدد
    public function showRequest(Request $request, int $id): JsonResponse
    {
        try {
            $maintenanceRequest = $this->technicianService->getRequestDetails(
                $request->user(),
                $id
            );

            return response()->json([
                'success' => true,
                'data' => new TechnicianMaintenanceRequestResource($maintenanceRequest)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //تقديم عرض سعر على طلب
    public function submitQuotation(StoreQuotationRequest $request, int $id): JsonResponse
    {
        try {
            $quotation = $this->technicianService->submitQuotation(
                $request->user(),
                $id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تقديم عرض السعر بنجاح',
                'data' => new TechnicianQuotationResource($quotation)
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //عرض طلباتي
    public function myJobs(Request $request): JsonResponse
    {
        try {
            $status = $request->get('status');
            $jobs = $this->technicianService->getMyJobs($request->user(), $status);

            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //عرض الطلبات المقبولة
    public function myAcceptedJobs(Request $request): JsonResponse
    {
        try {
            $jobs = $this->technicianService->getMyAcceptedJobs($request->user());

            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //تحديث حالة ال job
    public function updateJobStatus(UpdateJobStatusRequest $request, int $id): JsonResponse
    {
        try {
            $job = $this->technicianService->updateJobStatus(
                $request->user(),
                $id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث حالة المهمة بنجاح',
                'data' => $job
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    //إحصائيات
    public function statistics(Request $request): JsonResponse
    {
        try {
            $technician = $request->user()->technician;

            if (!$technician) {
                return response()->json([
                    'success' => false,
                    'message' => 'أنت لست تقنياً'
                ], 403);
            }

            $stats = [
                'total_jobs' => ServiceJob::where('technician_id', $technician->user_id)->count(),
                'assigned_jobs' => ServiceJob::where('technician_id', $technician->user_id)
                    ->where('status', 'assigned')->count(),
                'in_progress_jobs' => ServiceJob::where('technician_id', $technician->user_id)
                    ->where('status', 'in_progress')->count(),
                'completed_jobs' => ServiceJob::where('technician_id', $technician->user_id)
                    ->where('status', 'completed')->count(),

                    'total_quotations' => Quotation::where('technician_id', $technician->user_id)->count(),
                'pending_quotations' => Quotation::where('technician_id', $technician->user_id)
                    ->where('status', 'pending')->count(),
                'accepted_quotations' => Quotation::where('technician_id', $technician->user_id)
                    ->where('status', 'accepted')->count(),

                'total_earnings' => Quotation::where('technician_id', $technician->user_id)
                    ->where('status', 'accepted')
                    ->sum('price'),

                'average_rating' => $technician->average_rating ?? 0,
                'total_ratings' => $technician->ratings_count ?? 0,
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
