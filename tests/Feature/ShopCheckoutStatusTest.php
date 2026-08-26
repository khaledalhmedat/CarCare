<?php

// للتذكير: هذا الملف يختبر أن الدفع (checkout) يُعيد التحقق من حالة المتجر (نشط/معتمد) وقت إنشاء الطلب.

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ShopCheckoutStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeShop(bool $isActive, string $status): User
    {
        $ownerUser = $this->makeUserWithRole('shop-owner');
        Shop::create([
            'user_id' => $ownerUser->id, 'name' => 'AutoParts', 'phone' => '05',
            'city' => 'دمشق', 'is_active' => $isActive, 'status' => $status,
        ]);

        return $ownerUser;
    }

    private function makeProduct(User $ownerUser, int $stock, float $price = 100): Product
    {
        $shop = Shop::where('user_id', $ownerUser->id)->first();

        return Product::create([
            'shop_id' => $shop->id, 'name' => 'Part-' . uniqid(), 'price' => $price, 'stock_quantity' => $stock,
        ]);
    }

    private function addToCart(User $customer, Product $product, int $quantity): Cart
    {
        return Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function checkoutPayload(): array
    {
        return ['latitude' => 33.5460, 'longitude' => 36.3249, 'address_note' => 'بجانب الجامع'];
    }

    public function test_active_and_approved_shop_checkout_succeeds(): void
    {
        $owner = $this->makeShop(true, 'approved');
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 2);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, Order::count());
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_inactive_shop_checkout_fails(): void
    {
        $owner = $this->makeShop(false, 'approved');
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 2);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals(1, Cart::where('user_id', $customer->id)->count());
    }

    public function test_non_approved_shop_checkout_fails(): void
    {
        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $owner = $this->makeShop(true, $status);
            $product = $this->makeProduct($owner, 10);
            $customer = $this->makeUser();
            $this->addToCart($customer, $product, 2);
            Sanctum::actingAs($customer);

            $this->postJson('/api/customer/orders', $this->checkoutPayload())
                ->assertStatus(500)
                ->assertJson(['success' => false]);

            $this->assertEquals(10, $product->fresh()->stock_quantity);
            $this->assertEquals(1, Cart::where('user_id', $customer->id)->count());
        }

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
    }

    public function test_shop_deactivated_after_add_to_cart_blocks_checkout_without_mutation(): void
    {
        $owner = $this->makeShop(true, 'approved');
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 2);

        // المتجر أصبح غير نشط بعد إضافة المنتج للسلة وقبل الدفع
        Shop::where('user_id', $owner->id)->update(['is_active' => false]);

        Sanctum::actingAs($customer);
        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_overselling_protection_still_enforced_alongside_shop_check(): void
    {
        $owner = $this->makeShop(true, 'approved');
        $product = $this->makeProduct($owner, 1);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 5);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, Order::count());
        $this->assertEquals(1, $product->fresh()->stock_quantity);
    }
}
