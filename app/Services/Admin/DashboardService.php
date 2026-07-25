<?php

namespace App\Services\Admin;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\MaintenanceRequest;
use App\Models\Order;
use App\Models\Shop;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Per-operation "pending-equivalent" and "completed-equivalent" status values.
     * Each operation table uses its own status vocabulary (e.g. SOS uses "open",
     * spare-parts orders use "delivered"), so these are declared explicitly rather
     * than assumed uniform.
     */
    protected const OPERATION_STATUS_MAP = [
        'maintenance_requests' => ['pending' => 'pending', 'completed' => 'completed'],
        'sos_requests'         => ['pending' => 'open',    'completed' => 'completed'],
        'fuel_orders'          => ['pending' => 'pending', 'completed' => 'completed'],
        'carwash_bookings'     => ['pending' => 'pending', 'completed' => 'completed'],
        'orders'               => ['pending' => 'pending', 'completed' => 'delivered'],
    ];

    // ===================== 1B: Summary =====================

    public function summary(): array
    {
        return [
            'users' => [
                'total_users' => User::count(),
                // pure customers = authenticated users who own no provider profile
                'total_customers' => $this->customersWithoutProviderProfile(),
            ],
            'providers' => [
                'technicians'    => $this->providerStatusCounts(Technician::query()),
                'car_washers'    => $this->providerStatusCounts(CarWasher::query()),
                'fuel_providers' => $this->providerStatusCounts(FuelProvider::query()),
                'shops'          => $this->providerStatusCounts(Shop::query()),
            ],
            'operations' => $this->operationSummary(),
        ];
    }

    protected function providerStatusCounts($query): array
    {
        // one grouped query per provider type instead of five separate counts
        $counts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total'     => (int) $counts->sum(),
            'pending'   => (int) ($counts['pending'] ?? 0),
            'approved'  => (int) ($counts['approved'] ?? 0),
            'rejected'  => (int) ($counts['rejected'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
        ];
    }

    protected function customersWithoutProviderProfile(): int
    {
        return User::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('technicians')
                ->whereColumn('technicians.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('car_washers')
                ->whereColumn('car_washers.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('fuel_providers')
                ->whereColumn('fuel_providers.user_id', 'users.id')
                ->whereNull('fuel_providers.deleted_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shops')
                ->whereColumn('shops.user_id', 'users.id'))
            ->count();
    }

    protected function operationSummary(): array
    {
        $maintenance = $this->singleOperationSummary(MaintenanceRequest::query(), 'maintenance_requests');
        $sos         = $this->singleOperationSummary(SosRequest::query(), 'sos_requests');
        $fuel        = $this->singleOperationSummary(FuelOrder::query(), 'fuel_orders');
        $carwash     = $this->singleOperationSummary(CarwashBooking::query(), 'carwash_bookings');
        $spareParts  = $this->singleOperationSummary(Order::query(), 'orders');

        $groups = compact('maintenance', 'sos', 'fuel', 'carwash', 'spareParts');

        $completed = array_sum(array_column($groups, 'completed'));
        $pending   = array_sum(array_column($groups, 'pending'));

        return [
            'maintenance_requests' => $maintenance,
            'sos_requests'         => $sos,
            'fuel_orders'          => $fuel,
            'carwash_bookings'     => $carwash,
            'spare_parts_orders'   => $spareParts,
            'totals' => [
                'completed_operations' => $completed,
                'pending_operations'   => $pending,
            ],
        ];
    }

    protected function singleOperationSummary($query, string $table): array
    {
        $map = self::OPERATION_STATUS_MAP[$table];

        $counts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total'     => (int) $counts->sum(),
            'pending'   => (int) ($counts[$map['pending']] ?? 0),
            'completed' => (int) ($counts[$map['completed']] ?? 0),
        ];
    }

    // ===================== 1C: Operations over time =====================

    public function operations(array $params): array
    {
        [$from, $to] = $this->resolveRange($params);
        $groupBy = $params['group_by'] ?? 'day';
        $format = $this->dateFormat($groupBy);

        return [
            'range' => [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'group_by' => $groupBy,
            ],
            'maintenance' => $this->groupedCounts('maintenance_requests', $format, $from, $to),
            'sos'         => $this->groupedCounts('sos_requests', $format, $from, $to),
            'fuel'        => $this->groupedCounts('fuel_orders', $format, $from, $to),
            'car_wash'    => $this->groupedCounts('carwash_bookings', $format, $from, $to),
            'spare_parts' => $this->groupedCounts('orders', $format, $from, $to),
        ];
    }

    /**
     * Grouped counts done entirely in SQL (DATE_FORMAT + GROUP BY) — never loads rows into PHP.
     * $format is chosen from a fixed whitelist, so it is safe to inline.
     */
    protected function groupedCounts(string $table, string $format, Carbon $from, Carbon $to): array
    {
        return DB::table($table)
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket, COUNT(*) as total")
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => ['bucket' => $row->bucket, 'total' => (int) $row->total])
            ->all();
    }

    // ===================== 1D: Revenue =====================

    public function revenue(array $params): array
    {
        $from = isset($params['from']) ? Carbon::parse($params['from'])->startOfDay() : null;
        $to   = isset($params['to']) ? Carbon::parse($params['to'])->endOfDay() : null;

        $applyRange = function ($query, string $column) use ($from, $to) {
            if ($from) {
                $query->where($column, '>=', $from);
            }
            if ($to) {
                $query->where($column, '<=', $to);
            }
            return $query;
        };

        // Maintenance revenue = price of the accepted quotation on completed requests.
        // Linked via the service_job (created from the accepted quotation) rather than
        // maintenance_requests.accepted_quotation_id, which is not a real column in the schema.
        $maintenance = (float) $applyRange(
            DB::table('maintenance_requests as mr')
                ->join('service_jobs as sj', 'sj.maintenance_request_id', '=', 'mr.id')
                ->join('quotations as q', 'q.id', '=', 'sj.quotation_id')
                ->where('mr.status', 'completed'),
            'mr.created_at'
        )->sum('q.price');

        $fuel = (float) $applyRange(
            FuelOrder::where('status', FuelOrder::STATUS_COMPLETED),
            'created_at'
        )->sum('total_price');

        $carWash = (float) $applyRange(
            CarwashBooking::where('status', CarwashBooking::STATUS_COMPLETED),
            'created_at'
        )->sum('price');

        $spareParts = (float) $applyRange(
            Order::where('status', Order::STATUS_DELIVERED),
            'created_at'
        )->sum('total_price');

        $sos = 0.0; // no price/amount field exists on sos_requests

        $gross = round($maintenance + $fuel + $carWash + $spareParts + $sos, 2);

        return [
            'range' => [
                'from' => $from?->toDateTimeString(),
                'to' => $to?->toDateTimeString(),
            ],
            'gross_revenue' => [
                'maintenance' => round($maintenance, 2),
                'fuel'        => round($fuel, 2),
                'car_wash'    => round($carWash, 2),
                'spare_parts' => round($spareParts, 2),
                'sos'         => round($sos, 2),
                'total'       => $gross,
            ],
            // No commission/profit fields exist anywhere — returned as zero, never invented.
            'platform_commission' => 0.0,
            'net_profit' => 0.0,
            'notes' => [
                'currency' => null,
                'sources' => [
                    'maintenance' => 'quotations.price via service_jobs.quotation_id for maintenance_requests where status=completed',
                    'fuel'        => 'fuel_orders.total_price where status=completed',
                    'car_wash'    => 'carwash_bookings.price where status=completed',
                    'spare_parts' => 'orders.total_price where status=delivered',
                ],
                'missing_fields' => [
                    'sos_requests has no price/amount field — SOS revenue cannot be computed (returned 0).',
                    'No platform commission field exists on any operation table — platform_commission returned 0.',
                    'No profit/cost field exists — net_profit returned 0.',
                    'payments table exists but is never written to by any code path — not used as a revenue source.',
                    'No currency field is stored anywhere — currency returned null.',
                ],
            ],
        ];
    }

    // ===================== helpers =====================

    protected function resolveRange(array $params): array
    {
        if (isset($params['from']) || isset($params['to'])) {
            $from = isset($params['from'])
                ? Carbon::parse($params['from'])->startOfDay()
                : Carbon::parse($params['to'])->startOfDay()->subDays(30);
            $to = isset($params['to'])
                ? Carbon::parse($params['to'])->endOfDay()
                : Carbon::now()->endOfDay();

            return [$from, $to];
        }

        $period = $params['period'] ?? 'month';

        return match ($period) {
            'day'   => [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()],
            'year'  => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
    }

    protected function dateFormat(string $groupBy): string
    {
        return match ($groupBy) {
            'month' => '%Y-%m',
            'year'  => '%Y',
            default => '%Y-%m-%d',
        };
    }
}
