<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderInvoiceResource;
use App\Models\ProviderInvoice;
use App\Services\Admin\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderInvoiceController extends Controller
{
    public function __construct(protected BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'provider_type' => ['nullable', Rule::in(BillingService::PROVIDER_TYPES)],
            'provider_id' => 'nullable|integer',
            'status' => 'nullable|in:draft,issued,paid,overdue,cancelled',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = ProviderInvoice::with('items')
            ->when($request->filled('provider_type'), fn ($q) => $q->where('provider_type', $request->provider_type))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->filled('period_start'), fn ($q) => $q->whereDate('period_start', $request->period_start))
            ->when($request->filled('period_end'), fn ($q) => $q->whereDate('period_end', $request->period_end))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest();

        // 'overdue' is not a stored status — translate it to issued + past due.
        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->where('status', ProviderInvoice::STATUS_ISSUED)
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now());
            } else {
                $query->where('status', $request->status);
            }
        }

        $invoices = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => ProviderInvoiceResource::collection($invoices),
            'meta' => [
                'total' => $invoices->total(),
                'per_page' => $invoices->perPage(),
                'current_page' => $invoices->currentPage(),
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['nullable', 'required_with:provider_id', Rule::in(BillingService::PROVIDER_TYPES)],
            'provider_id' => 'nullable|required_with:provider_type|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $result = $this->service->generate($validated);

        return response()->json([
            'success' => true,
            'message' => 'تمت معالجة توليد الفواتير',
            'data' => $result,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = ProviderInvoice::with('items')->find($id);

        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ProviderInvoiceResource($invoice),
        ]);
    }

    public function issue(int $id): JsonResponse
    {
        return $this->mutate($id, fn ($invoice) => $this->service->issue($invoice), 'تم إصدار الفاتورة');
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'external_payment_method' => ['required', Rule::in(BillingService::PAYMENT_METHODS)],
            'external_payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->mutate(
            $id,
            fn ($invoice) => $this->service->markPaid($invoice, $request->user()->id, $validated),
            'تم تأكيد الدفع بنجاح'
        );
    }

    public function cancel(int $id): JsonResponse
    {
        return $this->mutate($id, fn ($invoice) => $this->service->cancel($invoice), 'تم إلغاء الفاتورة');
    }

    private function mutate(int $id, callable $action, string $message): JsonResponse
    {
        $invoice = ProviderInvoice::with('items')->find($id);

        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        try {
            $invoice = $action($invoice);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new ProviderInvoiceResource($invoice),
        ]);
    }
}
