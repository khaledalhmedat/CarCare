<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\User;
use App\Models\Product;

interface ShopRepositoryInterface
{
    public function findByUser(User $user): ?Shop;
    public function createForUser(User $user, array $data): Shop;
    public function update(Shop $shop, array $data): bool;
    public function findProduct(int $productId): ?Product;
    public function createProduct(Shop $shop, array $data): Product;
    public function updateProduct(Product $product, array $data): bool;
    public function deleteProduct(Product $product): bool;
    public function getShopProducts(Shop $shop);
}