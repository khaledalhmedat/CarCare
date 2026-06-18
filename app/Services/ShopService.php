<?php

namespace App\Services;

use App\Models\User;
use App\Models\Shop;
use App\Models\Product;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopService
{
    public function __construct(protected ShopRepositoryInterface $repository) {}

    public function getProfile(User $user): ?Shop
    {
        return $this->repository->findByUser($user);
    }

    public function createOrUpdateShop(User $user, array $data): Shop
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);

            if ($shop) {
                $this->repository->update($shop, $data);
            } else {
                $shop = $this->repository->createForUser($user, $data);
                // $user->assignRole('shop_owner');
            }

            // تحديث العلاقات
            if (isset($data['business_types'])) {
                $shop->businessTypes()->sync($data['business_types']);
            }
            if (isset($data['car_brands'])) {
                $shop->carBrands()->sync($data['car_brands']);
            }
            if (isset($data['part_categories'])) {
                $shop->partCategories()->sync($data['part_categories']);
            }

            DB::commit();
            return $shop->fresh(['businessTypes', 'carBrands', 'partCategories']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createProduct(User $user, array $data, $imageFiles = null): Product
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);
            if (!$shop) {
                throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
            }

            $product = $this->repository->createProduct($shop, $data);

            // رفع الصور
            if ($imageFiles) {
                foreach ($imageFiles as $index => $image) {
                    $path = $image->store('products', 'public');
                    $product->images()->create([
                        'image_path' => $path,
                        'is_primary' => $index === 0,
                    ]);
                }
            }

            DB::commit();
            return $product->load(['images', 'carBrand', 'partCategory']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateProduct(User $user, int $productId, array $data, $imageFiles = null): Product
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);
            if (!$shop) {
                throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
            }

            $product = $this->repository->findProduct($productId);
            if (!$product || $product->shop_id !== $shop->id) {
                throw new \Exception('المنتج غير موجود أو لا يخصك');
            }

            $this->repository->updateProduct($product, $data);

            // رفع صور جديدة
            if ($imageFiles) {
                foreach ($imageFiles as $image) {
                    $path = $image->store('products', 'public');
                    $product->images()->create([
                        'image_path' => $path,
                        'is_primary' => false,
                    ]);
                }
            }

            DB::commit();
            return $product->fresh(['images', 'carBrand', 'partCategory']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteProduct(User $user, int $productId): bool
    {
        $shop = $this->repository->findByUser($user);
        if (!$shop) {
            throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
        }

        $product = $this->repository->findProduct($productId);
        if (!$product || $product->shop_id !== $shop->id) {
            throw new \Exception('المنتج غير موجود أو لا يخصك');
        }

        // حذف الصور
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();
        }

        return $this->repository->deleteProduct($product);
    }

    public function getShopProducts(User $user)
    {
        $shop = $this->repository->findByUser($user);
        if (!$shop) {
            throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
        }
        return $this->repository->getShopProducts($shop);
    }
}