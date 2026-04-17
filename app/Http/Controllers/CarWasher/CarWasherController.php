<?php

namespace App\Http\Controllers\CarWasher;

use App\Http\Controllers\Controller;
use App\Http\Resources\CarWasherResource;
use App\Http\Resources\CarwashBookingResource;
use App\Services\CarWasherService;
use App\Services\CarwashService;
use Illuminate\Http\Request;

class CarWasherController extends Controller
{
    public function __construct(
        protected CarWasherService $carWasherService,
        protected CarwashService $carwashService
    ) {}

   
    public function myProfile(Request $request)
    {
        $carWasher = $this->carWasherService->getProfile($request->user());
        
        if (!$carWasher) {
            return response()->json(['success' => false, 'message' => 'لم تقم بإدخال معلومات مغسلتك بعد'], 404);
        }
        
        return response()->json(['success' => true, 'data' => new CarWasherResource($carWasher)]);
    }

    public function storeOrUpdateProfile(Request $request)
    {
        $validated = $request->validate([
            'shop_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'description' => 'nullable|string|max:1000',
            'services' => 'nullable|array',
            'service_prices' => 'nullable|array',
            'working_hours' => 'nullable|array',
        ]);
        
        $carWasher = $this->carWasherService->createOrUpdateProfile(
            $request->user(),
            $validated
        );
        
        return response()->json([
            'success' => true,
            'message' => $carWasher->wasRecentlyCreated ? 'تم إضافة معلومات المغسلة بنجاح' : 'تم تحديث معلومات المغسلة بنجاح',
            'data' => new CarWasherResource($carWasher)
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);
        
        $carWasher = $this->carWasherService->uploadLogo(
            $request->user(),
            $request->file('logo')
        );
        
        return response()->json([
            'success' => true,
            'message' => 'تم رفع الشعار بنجاح',
            'data' => [
                'logo_url' => asset('storage/' . $carWasher->logo),
                'logo_path' => $carWasher->logo
            ]
        ]);
    }

    public function deleteLogo(Request $request)
    {
        $this->carWasherService->deleteLogo($request->user());
        
        return response()->json([
            'success' => true,
            'message' => 'تم حذف الشعار بنجاح'
        ]);
    }
    public function myBookings(Request $request)
    {
        $bookings = $this->carwashService->getCarWasherBookings($request->user(), $request->status);
        
        return response()->json([
            'success' => true,
            'data' => CarwashBookingResource::collection($bookings),
            'meta' => ['total' => $bookings->total(), 'per_page' => $bookings->perPage(), 'current_page' => $bookings->currentPage()]
        ]);
    }

    public function acceptBooking(Request $request, int $bookingId)
    {
        $booking = $this->carwashService->acceptBooking($request->user(), $bookingId);
        
        return response()->json([
            'success' => true,
            'message' => 'تم قبول طلب الغسيل بنجاح',
            'data' => new CarwashBookingResource($booking)
        ]);
    }

    public function rejectBooking(Request $request, int $bookingId)
    {
        $request->validate(['rejection_reason' => 'nullable|string|max:500']);
        
        $booking = $this->carwashService->rejectBooking($request->user(), $bookingId, $request->rejection_reason);
        
        return response()->json([
            'success' => true,
            'message' => 'تم رفض طلب الغسيل',
            'data' => new CarwashBookingResource($booking)
        ]);
    }

    public function updateBookingStatus(Request $request, int $bookingId)
    {
        $validated = $request->validate(['status' => 'required|in:in_progress,completed']);
        
        $booking = $this->carwashService->updateBookingStatus($request->user(), $bookingId, $validated['status']);
        
        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data' => new CarwashBookingResource($booking)
        ]);
    }

    public function updateAvailability(Request $request)
    {
        $validated = $request->validate(['is_available' => 'required|boolean']);
        
        $this->carWasherService->updateAvailability($request->user(), $validated['is_available']);
        
        return response()->json([
            'success' => true,
            'message' => $validated['is_available'] ? 'المغسلة متاحة الآن' : 'المغسلة غير متاحة حالياً'
        ]);
    }

    public function statistics(Request $request)
    {
        $stats = $this->carWasherService->getStatistics($request->user());
        
        return response()->json(['success' => true, 'data' => $stats]);
    }
}