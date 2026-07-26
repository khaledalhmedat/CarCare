<?php

namespace App\Services\Admin;

use App\Http\Resources\CarWasherResource;
use App\Http\Resources\FuelProviderResource;
use App\Http\Resources\ShopResource;
use App\Http\Resources\TechnicianResource;
use App\Models\CarWasher;
use App\Models\FuelProvider;
use App\Models\Shop;
use App\Models\Technician;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class ProviderApprovalService
{
    public function __construct(protected NotificationService $notifications) {}

    protected const TYPES = [
        'technician' => [
            'model' => Technician::class,
            'resource' => TechnicianResource::class,
            'with' => ['user'],
        ],
        'car-washer' => [
            'model' => CarWasher::class,
            'resource' => CarWasherResource::class,
            'with' => ['user'],
        ],
        'fuel-provider' => [
            'model' => FuelProvider::class,
            'resource' => FuelProviderResource::class,
            'with' => ['user'],
        ],
        'shop' => [
            'model' => Shop::class,
            'resource' => ShopResource::class,
            'with' => ['user', 'businessTypes', 'carBrands', 'partCategories'],
        ],
    ];

    public function isSupportedType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public function resourceClass(string $type): string
    {
        $this->assertSupported($type);
        return self::TYPES[$type]['resource'];
    }

    protected function assertSupported(string $type): void
    {
        if (!$this->isSupportedType($type)) {
            throw new \InvalidArgumentException(
                'نوع مزود الخدمة غير مدعوم. الأنواع المتاحة: technician, car-washer, fuel-provider, shop'
            );
        }
    }

    protected function modelClass(string $type): string
    {
        $this->assertSupported($type);
        return self::TYPES[$type]['model'];
    }

    protected function eagerLoads(string $type): array
    {
        return self::TYPES[$type]['with'] ?? [];
    }

    public function list(string $type, array $filters = []): LengthAwarePaginator
    {
        $this->assertSupported($type);

        $modelClass = $this->modelClass($type);
        $status = $filters['status'] ?? 'pending';
        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = $perPage > 0 ? min($perPage, 50) : 15;

        $query = $modelClass::query()->with($this->eagerLoads($type));

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $query->latest()->paginate($perPage);
    }

    public function find(string $type, int $id): Model
    {
        $this->assertSupported($type);

        $modelClass = $this->modelClass($type);
        $provider = $modelClass::query()->with($this->eagerLoads($type))->find($id);

        if (!$provider) {
            throw new \Exception('لم يتم العثور على مزود الخدمة');
        }

        return $provider;
    }

    public function approve(string $type, int $id): Model
    {
        $provider = $this->find($type, $id);

        $provider->status = 'approved';
        $provider->approved_at = now();
        $provider->rejected_at = null;
        $provider->suspended_at = null;
        $provider->rejection_reason = null;
        $provider->save();

        $fresh = $provider->fresh($this->eagerLoads($type));
        $this->notifyProvider($fresh, $type, 'provider_approved', 'تمت الموافقة على الحساب', 'تمت الموافقة على حسابك كمزود خدمة.');

        return $fresh;
    }

    public function reject(string $type, int $id, string $reason): Model
    {
        $provider = $this->find($type, $id);

        $provider->status = 'rejected';
        $provider->rejected_at = now();
        $provider->rejection_reason = $reason;
        $provider->approved_at = null;
        $provider->suspended_at = null;
        $provider->save();

        $fresh = $provider->fresh($this->eagerLoads($type));
        $this->notifyProvider($fresh, $type, 'provider_rejected', 'تم رفض الحساب', 'تم رفض طلبك كمزود خدمة.');

        return $fresh;
    }

    public function suspend(string $type, int $id): Model
    {
        $provider = $this->find($type, $id);

        $provider->status = 'suspended';
        $provider->suspended_at = now();
        $provider->approved_at = null;
        $provider->rejected_at = null;
        $provider->rejection_reason = null;
        $provider->save();

        $fresh = $provider->fresh($this->eagerLoads($type));
        $this->notifyProvider($fresh, $type, 'provider_suspended', 'تم إيقاف الحساب', 'تم إيقاف حسابك كمزود خدمة مؤقتاً.');

        return $fresh;
    }

    public function reactivate(string $type, int $id): Model
    {
        $provider = $this->find($type, $id);

        $provider->status = 'approved';
        $provider->approved_at = now();
        $provider->rejected_at = null;
        $provider->suspended_at = null;
        $provider->rejection_reason = null;
        $provider->save();

        $fresh = $provider->fresh($this->eagerLoads($type));
        $this->notifyProvider($fresh, $type, 'provider_reactivated', 'تمت إعادة تفعيل الحساب', 'تمت إعادة تفعيل حسابك كمزود خدمة.');

        return $fresh;
    }

    /**
     * Notify the provider's owning user. Safe identifiers only; never throws
     * (NotificationService swallows failures), so approval flow is never broken.
     */
    protected function notifyProvider(Model $provider, string $type, string $notifType, string $title, string $body): void
    {
        $user = $provider->user;

        if ($user) {
            $this->notifications->notifyUser($user, $notifType, $title, $body, [
                'provider_type' => $type,
                'provider_id' => $provider->id,
                'status' => $provider->status,
            ]);
        }
    }
}
