<?php

namespace App\Services\Admin;

use App\Models\Advertisement;
use App\Models\CarWasher;
use App\Models\FuelProvider;
use App\Models\ProviderInvoice;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(
        protected DashboardService $dashboard,
        protected BillingService $billing
    ) {}

    /**
     * Operation type => table + status vocabulary (each operation uses its own words:
     * SOS "open", spare-parts "delivered", etc.). Used for normalized status buckets.
     */
    protected const OPERATIONS = [
        'maintenance' => ['table' => 'maintenance_requests', 'pending' => 'pending', 'completed' => 'completed', 'cancelled' => 'cancelled', 'in_progress' => ['in_progress']],
        'sos'         => ['table' => 'sos_requests',         'pending' => 'open',    'completed' => 'completed', 'cancelled' => 'cancelled', 'in_progress' => ['in_progress']],
        'fuel'        => ['table' => 'fuel_orders',          'pending' => 'pending', 'completed' => 'completed', 'cancelled' => 'cancelled', 'in_progress' => ['in_progress']],
        'car_wash'    => ['table' => 'carwash_bookings',     'pending' => 'pending', 'completed' => 'completed', 'cancelled' => 'cancelled', 'in_progress' => ['in_progress']],
        'spare_parts' => ['table' => 'orders',               'pending' => 'pending', 'completed' => 'delivered', 'cancelled' => 'cancelled', 'in_progress' => ['processing', 'out_for_delivery']],
    ];

    protected const PROVIDER_TYPE_TO_SOURCE = [
        'technician' => 'maintenance',
        'fuel-provider' => 'fuel',
        'car-washer' => 'car_wash',
        'shop' => 'spare_parts',
    ];

    // ==================== Overview ====================

    public function overview(array $filters): array
    {
        [$from, $to] = $this->dateBounds($filters);

        return [
            'range' => $this->rangeMeta($from, $to),
            'entities' => [
                'total_users' => User::count(),
                'total_customers' => $this->customersWithoutProviderProfile(),
                'total_providers' => $this->totalProviders(),
                'providers_by_type' => $this->providersByType(),
                'providers_by_status' => $this->providersByStatus(),
            ],
            'operations_summary' => $this->operationsSummary($from, $to),
            'revenue_summary' => $this->grossRevenue($from, $to, null),
            'billing_summary' => $this->billingTotals($from, $to, null),
            'advertisements_summary' => $this->adsCounts([]),
        ];
    }

    // ==================== Operations report ====================

    public function operations(array $filters): array
    {
        [$from, $to] = $this->dateBounds($filters);
        $type = $filters['operation_type'] ?? null;
        $status = $filters['status'] ?? null;
        $groupBy = $filters['group_by'] ?? null;

        $types = $type ? [$type] : array_keys(self::OPERATIONS);
        $result = [];
        $grand = ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'pending' => 0, 'in_progress' => 0];

        foreach ($types as $t) {
            $meta = self::OPERATIONS[$t];

            $byStatus = $this->rawStatusCounts($meta['table'], $from, $to, $status);
            $total = (int) $byStatus->sum();

            $normalized = [
                'completed' => (int) ($byStatus[$meta['completed']] ?? 0),
                'cancelled' => (int) ($byStatus[$meta['cancelled']] ?? 0),
                'pending' => (int) ($byStatus[$meta['pending']] ?? 0),
                'in_progress' => (int) collect($meta['in_progress'])->sum(fn ($s) => $byStatus[$s] ?? 0),
            ];

            $row = [
                'total' => $total,
                'by_status' => $byStatus->map(fn ($v) => (int) $v),
                'normalized' => $normalized,
            ];

            if ($groupBy) {
                $row['timeline'] = $this->timeline($meta['table'], $groupBy, $from, $to, $status);
            }

            $result[$t] = $row;

            $grand['total'] += $total;
            foreach ($normalized as $k => $v) {
                $grand[$k] += $v;
            }
        }

        return [
            'range' => $this->rangeMeta($from, $to),
            'group_by' => $groupBy,
            'by_operation_type' => $result,
            'totals' => $grand,
        ];
    }

    // ==================== Providers report ====================

    public function providers(array $filters): array
    {
        $onlyType = $filters['provider_type'] ?? null;

        // billing-status tally reuses Stage 2 logic
        $statusRows = $this->billing->providerStatuses(array_filter([
            'provider_type' => $onlyType,
            'provider_status' => $filters['provider_status'] ?? null,
            'billing_status' => $filters['billing_status'] ?? null,
        ]));

        $billingTally = collect($statusRows)
            ->groupBy('billing_status')
            ->map(fn ($rows) => $rows->count());

        return [
            'counts_by_type' => $this->providersByType($onlyType),
            'counts_by_provider_status' => $this->providersByStatus($onlyType),
            'counts_by_billing_status' => [
                'not_configured' => (int) ($billingTally['not_configured'] ?? 0),
                'exempt' => (int) ($billingTally['exempt'] ?? 0),
                'free_trial' => (int) ($billingTally['free_trial'] ?? 0),
                'active' => (int) ($billingTally['active'] ?? 0),
                'invoice_due' => (int) ($billingTally['invoice_due'] ?? 0),
                'overdue' => (int) ($billingTally['overdue'] ?? 0),
            ],
            'top_providers_by_completed_operations' => $this->topProviders($onlyType),
            'needing_action' => [
                'pending_approval' => $this->providersByStatus($onlyType, 'pending'),
                'overdue_billing_count' => (int) ($billingTally['overdue'] ?? 0),
                'not_configured_billing_count' => (int) ($billingTally['not_configured'] ?? 0),
            ],
        ];
    }

    // ==================== Financial report ====================

    public function financial(array $filters): array
    {
        [$from, $to] = $this->dateBounds($filters);
        $providerType = $filters['provider_type'] ?? null;
        $groupBy = $filters['group_by'] ?? null;

        $data = [
            'range' => $this->rangeMeta($from, $to),
            'group_by' => $groupBy,
            'gross_revenue' => $this->grossRevenue($from, $to, $providerType),
            'billing' => $this->billingTotals($from, $to, $providerType),
        ];

        if ($groupBy) {
            $data['timeline'] = $this->invoiceTimeline($groupBy, $from, $to, $providerType);
        }

        return $data;
    }

    // ==================== Billing report ====================

    public function billing(array $filters): array
    {
        [$from, $to] = $this->dateBounds($filters);
        $providerType = $filters['provider_type'] ?? null;
        $status = $filters['status'] ?? null;

        $base = fn () => $this->applyRange(
            ProviderInvoice::query()->when($providerType, fn ($q) => $q->where('provider_type', $providerType)),
            'created_at',
            $from,
            $to
        );

        $overdueCount = (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)
            ->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $issuedNotOverdue = (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>=', now()))->count();

        $draftCount = (clone $base())->where('status', ProviderInvoice::STATUS_DRAFT)->count();
        $paidCount = (clone $base())->where('status', ProviderInvoice::STATUS_PAID)->count();
        $cancelledCount = (clone $base())->where('status', ProviderInvoice::STATUS_CANCELLED)->count();
        $total = $draftCount + $issuedNotOverdue + $overdueCount + $paidCount + $cancelledCount;

        $latest = $base()
            ->when($status, function ($q) use ($status) {
                if ($status === 'overdue') {
                    $q->where('status', ProviderInvoice::STATUS_ISSUED)->whereNotNull('due_at')->where('due_at', '<', now());
                } else {
                    $q->where('status', $status);
                }
            })
            ->latest()->limit(10)->get();

        return [
            'range' => $this->rangeMeta($from, $to),
            'invoices_count' => $total,
            'draft_count' => $draftCount,
            'issued_count' => $issuedNotOverdue, // excludes overdue so the buckets partition the total
            'overdue_count' => $overdueCount,
            'paid_count' => $paidCount,
            'cancelled_count' => $cancelledCount,
            'paid_total' => (float) (clone $base())->where('status', ProviderInvoice::STATUS_PAID)->sum('total_amount'),
            'unpaid_total' => (float) (clone $base())->whereIn('status', [ProviderInvoice::STATUS_DRAFT, ProviderInvoice::STATUS_ISSUED])->sum('total_amount'),
            'overdue_total' => (float) (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)
                ->whereNotNull('due_at')->where('due_at', '<', now())->sum('total_amount'),
            'average_invoice_amount' => round((float) (clone $base())->avg('total_amount'), 2),
            'providers_with_overdue_count' => (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)
                ->whereNotNull('due_at')->where('due_at', '<', now())
                ->distinct()->count(DB::raw('CONCAT(provider_type, "-", provider_id)')),
            'latest_invoices' => $latest->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'provider_type' => $inv->provider_type,
                'provider_id' => $inv->provider_id,
                'total_amount' => (float) $inv->total_amount,
                'status' => $inv->status,
                'effective_status' => $inv->effectiveStatus(),
                'created_at' => $inv->created_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    // ==================== Advertisements report ====================

    public function advertisements(array $filters): array
    {
        return $this->adsCounts($filters);
    }

    protected function adsCounts(array $filters): array
    {
        [$from, $to] = $this->dateBounds($filters);
        $placement = $filters['placement'] ?? null;

        $base = fn () => $this->applyRange(
            Advertisement::query()->when($placement, fn ($q) => $q->where('placement', $placement)),
            'created_at',
            $from,
            $to
        );

        $latest = $base()
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('sort_order')->latest()->limit(10)->get();

        return [
            'range' => $this->rangeMeta($from, $to),
            'total_ads' => (clone $base())->count(),
            'active_ads' => (clone $base())->where('is_active', true)->count(),
            'inactive_ads' => (clone $base())->where('is_active', false)->count(),
            'expired_ads' => (clone $base())->whereNotNull('ends_at')->where('ends_at', '<', now())->count(),
            'scheduled_ads' => (clone $base())->whereNotNull('starts_at')->where('starts_at', '>', now())->count(),
            'ads_by_placement' => (clone $base())->select('placement', DB::raw('COUNT(*) as c'))
                ->groupBy('placement')->pluck('c', 'placement'),
            'latest_ads' => $latest->map(fn ($ad) => [
                'id' => $ad->id,
                'title' => $ad->title,
                'placement' => $ad->placement,
                'is_active' => (bool) $ad->is_active,
                'image_url' => $ad->image_url,
                'created_at' => $ad->created_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    // ==================== shared aggregate helpers ====================

    protected function operationsSummary(?Carbon $from, ?Carbon $to): array
    {
        $out = [];
        foreach (self::OPERATIONS as $type => $meta) {
            $byStatus = $this->rawStatusCounts($meta['table'], $from, $to, null);
            $out[$type] = [
                'total' => (int) $byStatus->sum(),
                'completed' => (int) ($byStatus[$meta['completed']] ?? 0),
                'pending' => (int) ($byStatus[$meta['pending']] ?? 0),
                'cancelled' => (int) ($byStatus[$meta['cancelled']] ?? 0),
                'in_progress' => (int) collect($meta['in_progress'])->sum(fn ($s) => $byStatus[$s] ?? 0),
            ];
        }
        return $out;
    }

    protected function rawStatusCounts(string $table, ?Carbon $from, ?Carbon $to, ?string $status)
    {
        $q = $this->applyRange(DB::table($table), 'created_at', $from, $to)
            ->when($status, fn ($qq) => $qq->where('status', $status))
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status');

        return $q->pluck('aggregate', 'status');
    }

    protected function timeline(string $table, string $groupBy, ?Carbon $from, ?Carbon $to, ?string $status): array
    {
        $format = $this->dateFormat($groupBy);

        return $this->applyRange(DB::table($table), 'created_at', $from, $to)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket, COUNT(*) as total")
            ->groupBy('bucket')->orderBy('bucket')->get()
            ->map(fn ($r) => ['bucket' => $r->bucket, 'total' => (int) $r->total])->all();
    }

    /**
     * Gross revenue by operation source. Delegates to DashboardService::revenue()
     * (which owns the safe maintenance join + missing-field notes). A provider_type
     * filter narrows the result to that type's single source.
     */
    protected function grossRevenue(?Carbon $from, ?Carbon $to, ?string $providerType): array
    {
        $params = [];
        if ($from) {
            $params['from'] = $from->toDateString();
        }
        if ($to) {
            $params['to'] = $to->toDateString();
        }

        $revenue = $this->dashboard->revenue($params);
        $gross = $revenue['gross_revenue'];

        if ($providerType && isset(self::PROVIDER_TYPE_TO_SOURCE[$providerType])) {
            $source = self::PROVIDER_TYPE_TO_SOURCE[$providerType];
            $only = $gross[$source] ?? 0;
            $gross = ['maintenance' => 0, 'fuel' => 0, 'car_wash' => 0, 'spare_parts' => 0, 'sos' => 0];
            $gross[$source] = $only;
            $gross['total'] = round((float) $only, 2);
        }

        return [
            'by_source' => $gross,
            'notes' => $revenue['notes']['missing_fields'] ?? [],
        ];
    }

    protected function billingTotals(?Carbon $from, ?Carbon $to, ?string $providerType): array
    {
        $base = fn () => $this->applyRange(
            ProviderInvoice::query()->when($providerType, fn ($q) => $q->where('provider_type', $providerType)),
            'created_at',
            $from,
            $to
        );

        return [
            'issued_total' => (float) (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)->sum('total_amount'),
            'paid_total' => (float) (clone $base())->where('status', ProviderInvoice::STATUS_PAID)->sum('total_amount'),
            'unpaid_total' => (float) (clone $base())->whereIn('status', [ProviderInvoice::STATUS_DRAFT, ProviderInvoice::STATUS_ISSUED])->sum('total_amount'),
            'overdue_total' => (float) (clone $base())->where('status', ProviderInvoice::STATUS_ISSUED)
                ->whereNotNull('due_at')->where('due_at', '<', now())->sum('total_amount'),
            'commission_total' => (float) (clone $base())->where('status', '!=', ProviderInvoice::STATUS_CANCELLED)->sum('commission_total'),
            'subscription_total' => (float) (clone $base())->where('status', '!=', ProviderInvoice::STATUS_CANCELLED)->sum('subscription_total'),
            'total_amount' => (float) (clone $base())->where('status', '!=', ProviderInvoice::STATUS_CANCELLED)->sum('total_amount'),
        ];
    }

    protected function invoiceTimeline(string $groupBy, ?Carbon $from, ?Carbon $to, ?string $providerType): array
    {
        $format = $this->dateFormat($groupBy);

        return $this->applyRange(
            DB::table('provider_invoices')->when($providerType, fn ($q) => $q->where('provider_type', $providerType))
                ->where('status', '!=', ProviderInvoice::STATUS_CANCELLED),
            'created_at',
            $from,
            $to
        )
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket, SUM(total_amount) as total, SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid")
            ->groupBy('bucket')->orderBy('bucket')->get()
            ->map(fn ($r) => ['bucket' => $r->bucket, 'total' => (float) $r->total, 'paid' => (float) $r->paid])->all();
    }

    // provider count helpers ---------------------------------------------------

    protected function providerModels(): array
    {
        return [
            'technician' => Technician::query(),
            'car-washer' => CarWasher::query(),
            'fuel-provider' => FuelProvider::query(),
            'shop' => Shop::query(),
        ];
    }

    protected function totalProviders(): int
    {
        $total = 0;
        foreach ($this->providerModels() as $q) {
            $total += (clone $q)->count();
        }
        return $total;
    }

    protected function providersByType(?string $onlyType = null): array
    {
        $out = [];
        foreach ($this->providerModels() as $type => $q) {
            if ($onlyType && $type !== $onlyType) {
                continue;
            }
            $out[$type] = (clone $q)->count();
        }
        return $out;
    }

    /**
     * @return array|int  nested per-type status counts, or a single int when $onlyStatus is given
     */
    protected function providersByStatus(?string $onlyType = null, ?string $onlyStatus = null)
    {
        $out = [];
        $flat = 0;
        foreach ($this->providerModels() as $type => $q) {
            if ($onlyType && $type !== $onlyType) {
                continue;
            }
            $counts = (clone $q)->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');

            if ($onlyStatus) {
                $flat += (int) ($counts[$onlyStatus] ?? 0);
                continue;
            }

            $out[$type] = [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'suspended' => (int) ($counts['suspended'] ?? 0),
            ];
        }

        return $onlyStatus ? $flat : $out;
    }

    protected function customersWithoutProviderProfile(): int
    {
        return User::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('technicians')->whereColumn('technicians.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('car_washers')->whereColumn('car_washers.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('fuel_providers')->whereColumn('fuel_providers.user_id', 'users.id')->whereNull('fuel_providers.deleted_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shops')->whereColumn('shops.user_id', 'users.id'))
            ->count();
    }

    protected function topProviders(?string $onlyType, int $limit = 5): array
    {
        $out = [];

        $builders = [
            'technician' => fn () => DB::table('maintenance_requests as mr')
                ->join('service_jobs as sj', 'sj.maintenance_request_id', '=', 'mr.id')
                ->join('technicians as t', 't.user_id', '=', 'sj.technician_id')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->where('mr.status', 'completed')
                ->select('t.id as provider_id', 'u.name as provider_name', DB::raw('COUNT(*) as completed_count'))
                ->groupBy('t.id', 'u.name'),
            'fuel-provider' => fn () => DB::table('fuel_orders as fo')
                ->join('fuel_providers as fp', 'fp.id', '=', 'fo.fuel_provider_id')
                ->where('fo.status', 'completed')
                ->select('fp.id as provider_id', 'fp.company_name as provider_name', DB::raw('COUNT(*) as completed_count'))
                ->groupBy('fp.id', 'fp.company_name'),
            'car-washer' => fn () => DB::table('carwash_bookings as cb')
                ->join('car_washers as cw', 'cw.id', '=', 'cb.car_washer_id')
                ->where('cb.status', 'completed')
                ->select('cw.id as provider_id', 'cw.shop_name as provider_name', DB::raw('COUNT(*) as completed_count'))
                ->groupBy('cw.id', 'cw.shop_name'),
            'shop' => fn () => DB::table('orders as o')
                ->join('shops as s', 's.id', '=', 'o.shop_id')
                ->where('o.status', 'delivered')
                ->select('s.id as provider_id', 's.name as provider_name', DB::raw('COUNT(*) as completed_count'))
                ->groupBy('s.id', 's.name'),
        ];

        foreach ($builders as $type => $make) {
            if ($onlyType && $type !== $onlyType) {
                continue;
            }
            /** @var QueryBuilder $q */
            $q = $make();
            $out[$type] = $q->orderByDesc('completed_count')->limit($limit)->get()
                ->map(fn ($r) => [
                    'provider_id' => (int) $r->provider_id,
                    'provider_name' => $r->provider_name,
                    'completed_count' => (int) $r->completed_count,
                ])->all();
        }

        return $out;
    }

    // date helpers -------------------------------------------------------------

    protected function dateBounds(array $filters): array
    {
        $from = !empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = !empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
        return [$from, $to];
    }

    protected function applyRange($query, string $column, ?Carbon $from, ?Carbon $to)
    {
        if ($from) {
            $query->where($column, '>=', $from);
        }
        if ($to) {
            $query->where($column, '<=', $to);
        }
        return $query;
    }

    protected function rangeMeta(?Carbon $from, ?Carbon $to): array
    {
        return [
            'from' => $from?->toDateTimeString(),
            'to' => $to?->toDateTimeString(),
        ];
    }

    protected function dateFormat(string $groupBy): string
    {
        return match ($groupBy) {
            'month' => '%Y-%m',
            'year' => '%Y',
            default => '%Y-%m-%d',
        };
    }
}
