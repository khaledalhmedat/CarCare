<?php

namespace App\Http\Controllers\TechnicianSos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\AcceptSosRequest;
use App\Http\Requests\Sos\CancelSosRequest;
use App\Http\Resources\SosResource;
use App\Services\TechnicianSosService;
use Illuminate\Http\Request;

class TechnicianSosController extends Controller
{
    public function __construct(protected TechnicianSosService $sosService) {}


    public function availableRequests(Request $request)
    {
        $technician = $request->user()->technician;

        if (!$technician || $technician->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'حسابك كتقني لم يتم اعتماده بعد من الإدارة'
            ], 403);
        }

        $requests = $this->sosService->getAvailableRequests(
            $technician?->latitude,
            $technician?->longitude,
            $technician?->city
        );

        return response()->json([
            'success' => true,
            'data' => SosResource::collection($requests),
            'meta' => [
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
                'current_page' => $requests->currentPage(),
            ]
        ]);
    }

    public function showRequest(int $id)
    {
        try {
            $request = $this->sosService->getRequestDetails($id);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => new SosResource($request)]);
    }

    public function acceptRequest(AcceptSosRequest $request, int $id)
    {
        $sosRequest = $this->sosService->acceptRequest($request->user(), $id, $request->validated());
        return response()->json([
            'success' => true,
            'message' => 'تم قبول طلب الطوارئ بنجاح',
            'data' => new SosResource($sosRequest)
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $validated = $request->validate(['status' => 'required|in:in_progress,completed']);
        $sosRequest = $this->sosService->updateStatus($request->user(), $id, $validated['status']);
        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data' => new SosResource($sosRequest)
        ]);
    }

    public function cancelRequest(CancelSosRequest $request, int $id)
    {
        $sosRequest = $this->sosService->cancelRequest($request->user(), $id, $request->cancellation_reason);
        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الطلب وإعادة فتحه',
            'data' => new SosResource($sosRequest)
        ]);
    }

    public function myRequests(Request $request)
    {
        $requests = $this->sosService->getMyRequests($request->user(), $request->status);
        return response()->json([
            'success' => true,
            'data' => SosResource::collection($requests),
            'meta' => ['total' => $requests->total(), 'per_page' => $requests->perPage(), 'current_page' => $requests->currentPage()]
        ]);
    }

    public function statistics(Request $request)
    {
        $stats = $this->sosService->getStatistics($request->user());
        return response()->json(['success' => true, 'data' => $stats]);
    }
}
