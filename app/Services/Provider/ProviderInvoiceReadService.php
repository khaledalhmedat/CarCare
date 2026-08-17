<?php

namespace App\Services\Provider;

// للتذكير: هذا الملف مسؤول فقط عن حل هوية المزود المصادق وعرض فواتيره للقراءة (لا تعديل).

use App\Models\ProviderInvoice;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ProviderInvoiceReadService
{
    /**
     * خريطة provider_type => Role slug المطلوب فعلياً امتلاكه (لاحظ أن دور المتجر
     * اسمه "shop-owner" بينما provider_type المخزَّن في الفواتير هو "shop").
     */
    private const ROLE_BY_PROVIDER_TYPE = [
        'technician' => 'technician',
        'car-washer' => 'car-washer',
        'fuel-provider' => 'fuel-provider',
        'shop' => 'shop-owner',
    ];

    /**
     * يحل هويات المزود الفعلية (provider_type + provider_id) المرتبطة بالمستخدم المصادق،
     * بشرط اجتماع الشرطين معاً: امتلاك الـRole المقابل (عبر User::hasRole()، نفس الطريقة
     * المستخدمة في CheckRole middleware) + وجود ملف المزود المقابل مملوكاً لهذا المستخدم.
     * لا يقبل أي مدخل من الطلب.
     */
    public function resolveProviderIdentities(User $user): array
    {
        $identities = [];

        if ($user->hasRole(self::ROLE_BY_PROVIDER_TYPE['technician']) && ($technician = $user->technician)) {
            $identities[] = ['provider_type' => 'technician', 'provider_id' => $technician->id];
        }

        if ($user->hasRole(self::ROLE_BY_PROVIDER_TYPE['car-washer']) && ($carWasher = $user->carWasher)) {
            $identities[] = ['provider_type' => 'car-washer', 'provider_id' => $carWasher->id];
        }

        if ($user->hasRole(self::ROLE_BY_PROVIDER_TYPE['fuel-provider']) && ($fuelProvider = $user->fuelProvider)) {
            $identities[] = ['provider_type' => 'fuel-provider', 'provider_id' => $fuelProvider->id];
        }

        // لا توجد علاقة User::shop() في المشروع؛ يُحل المتجر بنفس أسلوب ShopService
        if ($user->hasRole(self::ROLE_BY_PROVIDER_TYPE['shop'])) {
            $shop = Shop::where('user_id', $user->id)->first();
            if ($shop) {
                $identities[] = ['provider_type' => 'shop', 'provider_id' => $shop->id];
            }
        }

        return $identities;
    }

    public function getInvoicesForIdentities(array $identities, int $perPage = 15): LengthAwarePaginator
    {
        if (empty($identities)) {
            return ProviderInvoice::whereRaw('1 = 0')->paginate($perPage);
        }

        return ProviderInvoice::where(function ($query) use ($identities) {
                foreach ($identities as $identity) {
                    $query->orWhere(function ($q) use ($identity) {
                        $q->where('provider_type', $identity['provider_type'])
                            ->where('provider_id', $identity['provider_id']);
                    });
                }
            })
            ->latest()
            ->paginate($perPage);
    }

    public function findOwnedInvoice(int $id, array $identities): ?ProviderInvoice
    {
        if (empty($identities)) {
            return null;
        }

        return ProviderInvoice::with('items')
            ->where('id', $id)
            ->where(function ($query) use ($identities) {
                foreach ($identities as $identity) {
                    $query->orWhere(function ($q) use ($identity) {
                        $q->where('provider_type', $identity['provider_type'])
                            ->where('provider_id', $identity['provider_id']);
                    });
                }
            })
            ->first();
    }
}
