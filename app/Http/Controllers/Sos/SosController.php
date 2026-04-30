<?php

namespace App\Http\Controllers\Sos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\StoreSosRequest;
use App\Http\Resources\SosResource;
use App\Services\SosService;
use Illuminate\Http\Request;

class SosController extends Controller
{
    public function __construct(protected SosService $sosService) {}

    public function index(Request $request)
    {
        $requests = $this->sosService->getUserRequests($request->user(), $request->status);
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

    public function store(StoreSosRequest $request)
    {
        $sosRequest = $this->sosService->createRequest($request->user(), $request->validated());
        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب الطوارئ بنجاح',
            'data' => new SosResource($sosRequest)
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $sosRequest = $this->sosService->getRequest($id, $request->user());
        return response()->json(['success' => true, 'data' => new SosResource($sosRequest)]);
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate(['cancellation_reason' => 'required|string|min:5']);
        $this->sosService->cancelRequest($id, $request->user(), $request->cancellation_reason);
        return response()->json(['success' => true, 'message' => 'تم إلغاء الطلب بنجاح']);
    }
}