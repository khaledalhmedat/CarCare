<?php

namespace App\Http\Controllers\CarwashBooking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carwash\StoreCarwashBookingRequest;
use App\Http\Requests\Carwash\RateCarWasherRequest;
use App\Http\Resources\CarWashRatingResource;
use App\Http\Resources\CarwashBookingResource;
use App\Services\CarwashService;
use Illuminate\Http\Request;

class CarwashBookingController extends Controller
{
    public function __construct(
        protected CarwashService $carwashService
    ) {}

    public function availableCarWashers(Request $request)
    {
        $carWashers = $this->carwashService->getAvailableCarWashers($request->only(['city', 'service']));

        return response()->json([
            'success' => true,
            'data' => $carWashers,
            'meta' => ['total' => $carWashers->total(), 'per_page' => $carWashers->perPage(), 'current_page' => $carWashers->currentPage()]
        ]);
    }

    public function showCarWasher(int $id)
    {
        $carWasher = $this->carwashService->getCarWasherDetails($id);

        if (!$carWasher) {
            return response()->json(['success' => false, 'message' => 'المغسلة غير موجودة'], 404);
        }

        return response()->json(['success' => true, 'data' => $carWasher]);
    }

    public function store(StoreCarwashBookingRequest $request)
    {
        $booking = $this->carwashService->createBooking($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء طلب الغسيل بنجاح',
            'data' => new CarwashBookingResource($booking)
        ], 201);
    }

    public function index(Request $request)
    {
        $bookings = $this->carwashService->getUserBookings($request->user(), $request->status);

        return response()->json([
            'success' => true,
            'data' => CarwashBookingResource::collection($bookings),
            'meta' => ['total' => $bookings->total(), 'per_page' => $bookings->perPage(), 'current_page' => $bookings->currentPage()]
        ]);
    }

    public function show(Request $request, int $id)
    {
        $booking = $this->carwashService->getBooking($id, $request->user());

        return response()->json(['success' => true, 'data' => new CarwashBookingResource($booking)]);
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate(['cancellation_reason' => 'required|string|min:5']);

        $this->carwashService->cancelBooking($id, $request->user(), $request->cancellation_reason);

        return response()->json(['success' => true, 'message' => 'تم إلغاء الحجز بنجاح']);
    }

    public function rateCarWasher(RateCarWasherRequest $request, int $bookingId)
    {
        $rating = $this->carwashService->rateCarWasher(
            $request->user(),
            $bookingId,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تقييم المغسلة بنجاح',
            'data' => new CarWashRatingResource($rating)
        ]);
    }


    public function carWasherRatings(Request $request, int $carWasherId)
    {
        $ratings = $this->carwashService->getCarWasherRatings(
            $carWasherId,
            $request->get('per_page', 10)
        );

        return response()->json([
            'success' => true,
            'data' => CarWashRatingResource::collection($ratings),
            'meta' => [
                'average_rating' => $ratings->first()?->carWasher->average_rating ?? 0,
                'total_ratings' => $ratings->first()?->carWasher->ratings_count ?? 0,
                'total' => $ratings->total(),
                'per_page' => $ratings->perPage(),
                'current_page' => $ratings->currentPage(),
            ]
        ]);
    }
}
