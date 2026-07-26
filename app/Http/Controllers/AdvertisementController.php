<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicAdvertisementResource;
use App\Models\Advertisement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdvertisementController extends Controller
{
    /**
     * Public list of currently-live ads for the mobile app.
     * Left unauthenticated (like /api/health): ads are non-sensitive marketing
     * content and the app needs them before/without a logged-in user. Only the
     * minimal public shape is exposed (no admin/audit fields).
     */
    public function active(Request $request): JsonResponse
    {
        $request->validate([
            'placement' => ['nullable', Rule::in(Advertisement::PLACEMENTS)],
        ]);

        $ads = Advertisement::activeNow()
            ->when($request->filled('placement'), fn ($q) => $q->where('placement', $request->placement))
            ->orderBy('sort_order')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => PublicAdvertisementResource::collection($ads),
        ]);
    }
}
