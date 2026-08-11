<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\ProviderInvoiceReadResource;
use App\Services\Provider\ProviderInvoiceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderInvoiceController extends Controller
{
    public function __construct(protected ProviderInvoiceReadService $service) {}

    public function index(Request $request): JsonResponse
    {
        $identities = $this->service->resolveProviderIdentities($request->user());

        if (empty($identities)) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب غير مرتبط بأي ملف مزود خدمة',
            ], 403);
        }

        $invoices = $this->service->getInvoicesForIdentities($identities, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => ProviderInvoiceReadResource::collection($invoices),
            'meta' => [
                'total' => $invoices->total(),
                'per_page' => $invoices->perPage(),
                'current_page' => $invoices->currentPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identities = $this->service->resolveProviderIdentities($request->user());

        if (empty($identities)) {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب غير مرتبط بأي ملف مزود خدمة',
            ], 403);
        }

        $invoice = $this->service->findOwnedInvoice($id, $identities);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'الفاتورة غير موجودة',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ProviderInvoiceReadResource($invoice),
        ]);
    }
}
