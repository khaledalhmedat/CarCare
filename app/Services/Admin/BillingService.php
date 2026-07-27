<?php

namespace App\Services\Admin;

use App\Models\CarwashBooking;
use App\Models\FuelOrder;
use App\Models\Order;
use App\Models\ProviderBillingSetting;
use App\Models\ProviderInvoice;
use App\Models\ProviderInvoiceItem;
use App\Models\Technician;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(protected NotificationService $notifications) {}

    public const PROVIDER_TYPES = ['technician', 'car-washer', 'fuel-provider', 'shop'];
    public const BILLING_TYPES = [
        'monthly_subscription',
        'commission_per_order',
        'subscription_plus_commission',
        'exempt',
    ];
    public const PAYMENT_METHODS = ['sham_cash', 'syriatel_cash', 'bank_transfer', 'cash', 'other'];

    /**
     * provider_type => [table, display-name column (null = use user.name)].
     * provider_id everywhere is the provider PROFILE id.
     */
    protected const PROVIDER_MAP = [
        'technician'    => ['table' => 'technicians',    'name' => null],
        'car-washer'    => ['table' => 'car_washers',    'name' => 'shop_name'],
        'fuel-provider' => ['table' => 'fuel_providers', 'name' => 'company_name'],
        'shop'          => ['table' => 'shops',          'name' => 'name'],
    ];

    public function providerTable(string $type): ?string
    {
        return self::PROVIDER_MAP[$type]['table'] ?? null;
    }

    // ==================== Billing settings ====================

    /**
     * Create a setting. If it is active, deactivate any other active setting for the
     * same provider first (one active setting per provider — auto-deactivate approach).
     */
    public function createSetting(array $data): ProviderBillingSetting
    {
        return DB::transaction(function () use ($data) {
            $data['free_trial_days'] = $data['free_trial_days'] ?? 0;
            $data['payment_due_days'] = $data['payment_due_days'] ?? 7;
            $data['is_active'] = $data['is_active'] ?? true;

            if ($data['is_active']) {
                $this->deactivateActiveSettings($data['provider_type'], $data['provider_id']);
            }

            return ProviderBillingSetting::create($data);
        });
    }

    public function updateSetting(ProviderBillingSetting $setting, array $data): ProviderBillingSetting
    {
        return DB::transaction(function () use ($setting, $data) {
            $willBeActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $setting->is_active;

            if ($willBeActive) {
                $this->deactivateActiveSettings($setting->provider_type, $setting->provider_id, $setting->id);
            }

            $setting->update($data);

            return $setting->fresh();
        });
    }

    protected function deactivateActiveSettings(string $type, int $providerId, ?int $exceptId = null): void
    {
        ProviderBillingSetting::where('provider_type', $type)
            ->where('provider_id', $providerId)
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_active' => false]);
    }

    // ==================== Invoice generation ====================

    /**
     * Generate invoices for one provider or for all active settings within a period.
     * Returns a summary of generated + skipped (with reasons). Idempotent per period.
     */
    public function generate(array $params): array
    {
        $periodStart = Carbon::parse($params['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($params['period_end'])->endOfDay();

        $query = ProviderBillingSetting::where('is_active', true);

        if (!empty($params['provider_type']) && !empty($params['provider_id'])) {
            $query->where('provider_type', $params['provider_type'])
                ->where('provider_id', $params['provider_id']);
        }

        $settings = $query->get();

        $generated = [];
        $skipped = [];

        foreach ($settings as $setting) {
            $result = $this->generateForSetting($setting, $periodStart, $periodEnd);

            if ($result['status'] === 'generated') {
                $generated[] = $this->invoiceSummary($result['invoice']);
            } else {
                $skipped[] = array_merge([
                    'provider_type' => $setting->provider_type,
                    'provider_id' => $setting->provider_id,
                ], $result);
            }
        }

        return [
            'period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
            'generated_count' => count($generated),
            'skipped_count' => count($skipped),
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }

    protected function generateForSetting(ProviderBillingSetting $setting, Carbon $periodStart, Carbon $periodEnd): array
    {
        // Exempt providers never owe anything → no invoice is created (keeps the
        // ledger free of meaningless zero rows). Reported as skipped with a reason.
        if ($setting->billing_type === ProviderBillingSetting::BILLING_EXEMPT) {
            return ['status' => 'skipped', 'reason' => 'exempt'];
        }

        $existing = ProviderInvoice::where('provider_type', $setting->provider_type)
            ->where('provider_id', $setting->provider_id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->first();

        if ($existing) {
            return ['status' => 'skipped', 'reason' => 'already_exists', 'invoice_id' => $existing->id];
        }

        // Free trial: if the period starts on or before the trial end, the provider is
        // still within the trial → skip (no charge) rather than store a zero invoice
        // that would block a later real invoice for the same period.
        $trial = $this->trialWindow($setting);
        if ($trial['days'] > 0 && $trial['ends_at'] && $periodStart->lte($trial['ends_at'])) {
            return [
                'status' => 'skipped',
                'reason' => 'within_free_trial',
                'trial_ends_at' => $trial['ends_at']->toDateString(),
            ];
        }

        $items = [];
        $subscriptionTotal = 0.0;
        $commissionTotal = 0.0;

        if ($setting->hasSubscription()) {
            $fee = (float) ($setting->monthly_fee ?? 0);
            $subscriptionTotal += $fee;
            $items[] = [
                'item_type' => ProviderInvoiceItem::TYPE_SUBSCRIPTION,
                'source_type' => null,
                'source_id' => null,
                'description' => 'Monthly subscription fee',
                'amount' => round($fee, 2),
            ];
        }

        if ($setting->hasCommission()) {
            $gross = $this->grossForProvider($setting->provider_type, $setting->provider_id, $periodStart, $periodEnd);
            $percent = (float) ($setting->commission_percent ?? 0);
            $commission = round($gross * $percent / 100, 2);
            $commissionTotal += $commission;
            $items[] = [
                'item_type' => ProviderInvoiceItem::TYPE_COMMISSION,
                'source_type' => 'operations_gross',
                'source_id' => null,
                'description' => "Commission {$percent}% on completed operations gross {$gross}",
                'amount' => $commission,
            ];
        }

        $subtotal = round($subscriptionTotal + $commissionTotal, 2);

        return DB::transaction(function () use ($setting, $periodStart, $periodEnd, $items, $subscriptionTotal, $commissionTotal, $subtotal) {
            $invoice = ProviderInvoice::create([
                'invoice_number' => 'TEMP-' . Str::uuid(),
                'provider_type' => $setting->provider_type,
                'provider_id' => $setting->provider_id,
                'billing_setting_id' => $setting->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'subtotal' => $subtotal,
                'commission_total' => round($commissionTotal, 2),
                'subscription_total' => round($subscriptionTotal, 2),
                'total_amount' => $subtotal,
                'status' => ProviderInvoice::STATUS_DRAFT,
            ]);

            $invoice->invoice_number = sprintf('INV-%s-%06d', $periodStart->format('Ym'), $invoice->id);
            $invoice->save();

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            return ['status' => 'generated', 'invoice' => $invoice->fresh('items')];
        });
    }

    /**
     * Commission gross per provider type, filtered to the period by created_at.
     * Uses only the price fields confirmed to exist; SOS has none, so it is never sourced.
     */
    protected function grossForProvider(string $type, int $providerId, Carbon $from, Carbon $to): float
    {
        return match ($type) {
            'technician'    => $this->technicianGross($providerId, $from, $to),
            'car-washer'    => (float) CarwashBooking::where('car_washer_id', $providerId)
                ->where('status', 'completed')->whereBetween('created_at', [$from, $to])->sum('price'),
            'fuel-provider' => (float) FuelOrder::where('fuel_provider_id', $providerId)
                ->where('status', 'completed')->whereBetween('created_at', [$from, $to])->sum('total_price'),
            'shop'          => (float) Order::where('shop_id', $providerId)
                ->where('status', 'delivered')->whereBetween('created_at', [$from, $to])->sum('total_price'),
            default         => 0.0,
        };
    }

    protected function technicianGross(int $technicianId, Carbon $from, Carbon $to): float
    {
        $technician = Technician::find($technicianId);
        if (!$technician) {
            return 0.0;
        }

        // service_jobs.technician_id stores users.id, not technicians.id.
        return (float) DB::table('maintenance_requests as mr')
            ->join('service_jobs as sj', 'sj.maintenance_request_id', '=', 'mr.id')
            ->join('quotations as q', 'q.id', '=', 'sj.quotation_id')
            ->where('mr.status', 'completed')
            ->where('sj.technician_id', $technician->user_id)
            ->whereBetween('mr.created_at', [$from, $to])
            ->sum('q.price');
    }

    // ==================== Invoice lifecycle ====================

    public function issue(ProviderInvoice $invoice): ProviderInvoice
    {
        if ($invoice->status !== ProviderInvoice::STATUS_DRAFT) {
            throw new \Exception('لا يمكن إصدار الفاتورة إلا إذا كانت مسودة');
        }

        $dueDays = optional($invoice->billingSetting)->payment_due_days ?? 7;

        $invoice->update([
            'status' => ProviderInvoice::STATUS_ISSUED,
            'issued_at' => now(),
            'due_at' => now()->addDays($dueDays),
        ]);

        $fresh = $invoice->fresh('items');
        $this->notifyInvoice($fresh, 'invoice_issued', 'تم إصدار فاتورة', 'تم إصدار فاتورة جديدة لحسابك، يرجى المراجعة والدفع.');

        return $fresh;
    }

    public function markPaid(ProviderInvoice $invoice, int $adminId, array $data): ProviderInvoice
    {
        if (!in_array($invoice->status, [ProviderInvoice::STATUS_DRAFT, ProviderInvoice::STATUS_ISSUED], true)) {
            throw new \Exception('لا يمكن تأكيد الدفع لهذه الفاتورة في حالتها الحالية');
        }

        $invoice->update([
            'status' => ProviderInvoice::STATUS_PAID,
            'paid_at' => now(),
            'confirmed_by' => $adminId,
            'external_payment_method' => $data['external_payment_method'],
            'external_payment_reference' => $data['external_payment_reference'] ?? null,
            'notes' => $data['notes'] ?? $invoice->notes,
        ]);

        $fresh = $invoice->fresh('items');
        $this->notifyInvoice($fresh, 'invoice_paid', 'تم تأكيد الدفع', 'تم تأكيد دفع فاتورتك بنجاح.');

        return $fresh;
    }

    public function cancel(ProviderInvoice $invoice): ProviderInvoice
    {
        if ($invoice->status === ProviderInvoice::STATUS_PAID) {
            throw new \Exception('لا يمكن إلغاء فاتورة مدفوعة');
        }
        if ($invoice->status === ProviderInvoice::STATUS_CANCELLED) {
            throw new \Exception('الفاتورة ملغاة بالفعل');
        }

        $invoice->update(['status' => ProviderInvoice::STATUS_CANCELLED]);

        $fresh = $invoice->fresh('items');
        $this->notifyInvoice($fresh, 'invoice_cancelled', 'تم إلغاء الفاتورة', 'تم إلغاء إحدى فواتيرك.');

        return $fresh;
    }

    /**
     * Notify the invoice's provider user. Safe identifiers only (no payment
     * references); never throws, so invoice actions are never broken.
     */
    protected function notifyInvoice(ProviderInvoice $invoice, string $type, string $title, string $body): void
    {
        $user = $this->providerUser($invoice);

        if ($user) {
            $this->notifications->notifyUser($user, $type, $title, $body, [
                'invoice_id' => $invoice->id,
                'provider_type' => $invoice->provider_type,
                'provider_id' => $invoice->provider_id,
                'status' => $invoice->status,
            ]);
        }
    }

    protected function providerUser(ProviderInvoice $invoice): ?User
    {
        $table = $this->providerTable($invoice->provider_type);
        if (!$table) {
            return null;
        }

        $userId = DB::table($table)->where('id', $invoice->provider_id)->value('user_id');

        return $userId ? User::find($userId) : null;
    }

    // ==================== Provider billing status ====================

    public function providerStatuses(array $filters): array
    {
        $types = !empty($filters['provider_type']) ? [$filters['provider_type']] : self::PROVIDER_TYPES;
        $rows = [];

        foreach ($types as $type) {
            $table = $this->providerTable($type);
            if (!$table) {
                continue;
            }

            $query = DB::table($table . ' as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
                ->select('p.id', 'p.user_id', 'p.status as provider_status', 'p.approved_at', 'u.name as user_name');

            $nameCol = self::PROVIDER_MAP[$type]['name'];
            if ($nameCol) {
                $query->addSelect('p.' . $nameCol . ' as provider_name');
            }

            if ($table === 'fuel_providers') {
                $query->whereNull('p.deleted_at');
            }
            if (!empty($filters['provider_id'])) {
                $query->where('p.id', $filters['provider_id']);
            }
            if (!empty($filters['provider_status'])) {
                $query->where('p.status', $filters['provider_status']);
            }

            $providers = $query->get();
            if ($providers->isEmpty()) {
                continue;
            }

            // batch-load settings + invoices for all providers of this type (avoids N+1):
            // two queries per type instead of two queries per provider.
            $providerIds = $providers->pluck('id')->map(fn ($id) => (int) $id)->all();

            $settingsByProvider = ProviderBillingSetting::where('provider_type', $type)
                ->whereIn('provider_id', $providerIds)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get()
                ->groupBy('provider_id')
                ->map(fn ($group) => $group->first()); // highest id = latest active

            $invoicesByProvider = ProviderInvoice::where('provider_type', $type)
                ->whereIn('provider_id', $providerIds)
                ->get()
                ->groupBy('provider_id');

            foreach ($providers as $provider) {
                $pid = (int) $provider->id;
                $row = $this->buildProviderStatusRow(
                    $type,
                    $provider,
                    $nameCol,
                    $settingsByProvider->get($pid),
                    $invoicesByProvider->get($pid) ?? collect()
                );

                if (!empty($filters['billing_status']) && $row['billing_status'] !== $filters['billing_status']) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function buildProviderStatusRow(string $type, object $provider, ?string $nameCol, ?ProviderBillingSetting $setting, $invoices): array
    {
        $name = $nameCol ? ($provider->provider_name ?? null) : ($provider->user_name ?? null);
        $name = $name ?: ($provider->user_name ?? null);

        $base = [
            'provider_type' => $type,
            'provider_id' => (int) $provider->id,
            'provider_name' => $name,
            'provider_status' => $provider->provider_status,
            'billing_setting_id' => $setting?->id,
            'billing_type' => $setting?->billing_type,
            'free_trial_days' => $setting?->free_trial_days ?? 0,
            'trial_started_at' => null,
            'trial_ends_at' => null,
            'trial_remaining_days' => 0,
            'unpaid_invoices_count' => 0,
            'overdue_invoices_count' => 0,
            'unpaid_total' => 0.0,
            'last_invoice' => null,
        ];

        if (!$setting) {
            return array_merge($base, [
                'billing_status' => 'not_configured',
                'next_action_label' => 'إعداد الفوترة لهذا المزود',
            ]);
        }

        // invoice aggregates (from the pre-loaded collection — no per-provider query)
        $unpaid = $invoices->filter(fn ($i) => $i->isUnpaid());
        $overdue = $unpaid->filter(fn ($i) => $i->isOverdue());

        $base['unpaid_invoices_count'] = $unpaid->count();
        $base['overdue_invoices_count'] = $overdue->count();
        $base['unpaid_total'] = round((float) $unpaid->sum('total_amount'), 2);

        $last = $invoices->sortByDesc('id')->first();
        if ($last) {
            $base['last_invoice'] = $this->invoiceSummary($last);
        }

        if ($setting->billing_type === ProviderBillingSetting::BILLING_EXEMPT) {
            return array_merge($base, [
                'billing_status' => 'exempt',
                'next_action_label' => 'معفى من الفوترة',
            ]);
        }

        // free trial window
        $trial = $this->trialWindow($setting, $provider->approved_at);
        if ($trial['days'] > 0 && $trial['ends_at']) {
            $base['trial_started_at'] = $trial['starts_at']?->toDateString();
            $base['trial_ends_at'] = $trial['ends_at']->toDateString();

            if (now()->lt($trial['ends_at'])) {
                $base['trial_remaining_days'] = now()->startOfDay()->diffInDays($trial['ends_at']->copy()->startOfDay());
                return array_merge($base, [
                    'billing_status' => 'free_trial',
                    'next_action_label' => 'ضمن الفترة المجانية',
                ]);
            }
        }

        if ($overdue->count() > 0) {
            return array_merge($base, [
                'billing_status' => 'overdue',
                'next_action_label' => 'متابعة فاتورة متأخرة',
            ]);
        }

        if ($unpaid->count() > 0) {
            return array_merge($base, [
                'billing_status' => 'invoice_due',
                'next_action_label' => 'بانتظار الدفع',
            ]);
        }

        return array_merge($base, [
            'billing_status' => 'active',
            'next_action_label' => 'لا يوجد إجراء مطلوب',
        ]);
    }

    // ==================== helpers ====================

    /**
     * Trial window from approved_at (preferred) or setting.starts_at (fallback),
     * or the setting's creation date as a last resort.
     */
    protected function trialWindow(ProviderBillingSetting $setting, $approvedAt = null): array
    {
        $days = (int) ($setting->free_trial_days ?? 0);
        if ($days <= 0) {
            return ['days' => 0, 'starts_at' => null, 'ends_at' => null];
        }

        $approvedAt = $approvedAt ?? $this->providerApprovedAt($setting->provider_type, $setting->provider_id);

        $start = $approvedAt
            ? Carbon::parse($approvedAt)
            : ($setting->starts_at ? Carbon::parse($setting->starts_at) : Carbon::parse($setting->created_at));

        return [
            'days' => $days,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays($days),
        ];
    }

    protected function providerApprovedAt(string $type, int $providerId)
    {
        $table = $this->providerTable($type);
        if (!$table) {
            return null;
        }

        return DB::table($table)->where('id', $providerId)->value('approved_at');
    }

    protected function invoiceSummary(ProviderInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'total_amount' => (float) $invoice->total_amount,
            'status' => $invoice->status,
            'effective_status' => $invoice->effectiveStatus(),
            'due_at' => $invoice->due_at?->toIso8601String(),
        ];
    }
}
