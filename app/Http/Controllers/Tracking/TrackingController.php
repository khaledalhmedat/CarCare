<?php

namespace App\Http\Controllers\Tracking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\UpdateLocationRequest;
use App\Services\TrackingService;
use App\Services\TechnicianSosService;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function __construct(
        protected TrackingService $trackingService,
        protected TechnicianSosService $sosService
    ) {}

    
    public function shareLocation(UpdateLocationRequest $request, int $sosRequestId)
    {
        $technician = $request->user();
        
        $sosRequest = $this->sosService->getRequestDetails($sosRequestId);
        
        if ($sosRequest->technician_id !== $technician->id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطلب غير مرتبط بك'
            ], 403);
        }
        
        if ($sosRequest->status !== 'accepted' && $sosRequest->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن مشاركة الموقع في هذه المرحلة'
            ], 400);
        }
        
        $point = $this->trackingService->updateLocation(
            $sosRequest,
            $technician->id,
            $request->validated()
        );
        
        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الموقع',
            'data' => [
                'lat' => $point->lat,
                'lng' => $point->lng,
                'timestamp' => $point->created_at->toIso8601String(),
            ]
        ]);
    }

    
    public function trackTechnician(Request $request, int $sosRequestId)
    {
        $user = $request->user();
        
        $sosRequest = $this->sosService->getRequestDetails($sosRequestId);
        
        if ($sosRequest->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الطلب لا يخصك'
            ], 403);
        }
        
        if ($sosRequest->status === 'open') {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم تعيين فني بعد'
            ], 400);
        }
        
        $lastLocation = $this->trackingService->getLastLocation($sosRequestId);
        $trackingPath = $this->trackingService->getTrackingPoints($sosRequestId);
        
        return response()->json([
            'success' => true,
            'data' => [
                'sos_request' => [
                    'id' => $sosRequest->id,
                    'status' => $sosRequest->status,
                ],
                'technician' => [
                    'id' => $sosRequest->technician?->id,
                    'name' => $sosRequest->technician?->name,
                    'phone' => $sosRequest->technician?->phone,
                ],
                'last_location' => $lastLocation ? [
                    'lat' => $lastLocation->lat,
                    'lng' => $lastLocation->lng,
                    'timestamp' => $lastLocation->created_at->toIso8601String(),
                ] : null,
                'tracking_path' => $trackingPath['points'],
                'started_at' => $trackingPath['started_at']?->toIso8601String(),
            ]
        ]);
    }
}