<?php

// للتذكير: هذا الملف يختبر الإشعارات الدائمة لدورة طلب قطع الغيار (إنشاء، قبول، رفض، انتقالات الحالة، إلغاء العميل).

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SparePartsOrderNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeApprovedShop(): User
    {
        $ownerUser = $this->makeUserWithRole('shop-owner');
        Shop::create([
            'user_id' => $ownerUser->id, 'name' => 'AutoParts', 'phone' => '05',
            'city' => 'دمشق', 'is_active' => true, 'status' => 'approved',
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

    /**
     * ينشئ طلباً مباشرة (بدون checkout) بحالة مطلوبة، مع عناصره ومخزون مُنقَص مسبقاً،
     * ليطابق ما كان سينتج عن checkout فعلي بنفس الكميات.
     */
    private function makeOrderWithItems(User $owner, User $customer, Product $product, int $quantity, string $status): Order
    {
        $shop = Shop::where('user_id', $owner->id)->first();

        $order = Order::create([
            'user_id' => $customer->id, 'shop_id' => $shop->id, 'total_price' => $product->price * $quantity,
            'customer_latitude' => 33.54, 'customer_longitude' => 36.32, 'status' => $status,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => $quantity, 'price' => $product->price,
        ]);
        $product->decrement('stock_quantity', $quantity);

        return $order;
    }

    public function test_e1_order_creation_notifies_shop_owner_only(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10, 100);
        $customer = $this->makeUser();
        $unrelated = $this->makeUser();
        $this->addToCart($customer, $product, 2);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $owner->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $order = Order::first();
        $shop = Shop::where('user_id', $owner->id)->first();
        $notification = $owner->notifications()->first();
        $this->assertEquals('spare_parts_order_received', $notification->type);
        $this->assertEquals('طلب قطع غيار جديد', $notification->data['title']);
        $this->assertEquals('تم إنشاء طلب جديد في متجرك ويحتاج إلى المراجعة', $notification->data['body']);
        $this->assertEquals('spare_parts_order', $notification->data['data']['entity_type']);
        $this->assertEquals($order->id, $notification->data['data']['entity_id']);
        $this->assertEquals('open_details', $notification->data['data']['action']);
        $this->assertEquals('pending', $notification->data['data']['status']);
        $this->assertEquals($shop->id, $notification->data['data']['shop_id']);
        $this->assertEquals(200, $notification->data['data']['total_price']);
    }

    public function test_e2_accept_notifies_customer_only(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $unrelated = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $owner->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $shop = Shop::where('user_id', $owner->id)->first();
        $notification = $customer->notifications()->first();
        $this->assertEquals('spare_parts_order_accepted', $notification->type);
        $this->assertEquals([
            'entity_type' => 'spare_parts_order',
            'entity_id' => $order->id,
            'action' => 'open_details',
            'status' => 'accepted',
            'shop_id' => $shop->id,
        ], $notification->data['data']);
    }

    public function test_e3_reject_notifies_customer_with_reason(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'نفدت الكمية لدينا'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $notification = $customer->notifications()->first();
        $this->assertEquals('spare_parts_order_rejected', $notification->type);
        $this->assertEquals('cancelled', $notification->data['data']['status']);
        $this->assertEquals('نفدت الكمية لدينا', $notification->data['data']['reason']);
    }

    public function test_e4_processing_notifies_customer_once(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'accepted');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('spare_parts_order_processing', $customer->notifications()->first()->type);
    }

    public function test_e5_out_for_delivery_notifies_customer_once(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'processing');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'out_for_delivery'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('spare_parts_order_out_for_delivery', $customer->notifications()->first()->type);
    }

    public function test_e6_delivered_notifies_customer_once(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'out_for_delivery');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('spare_parts_order_delivered', $customer->notifications()->first()->type);
    }

    public function test_e7_customer_cancel_from_pending_notifies_shop_owner(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $unrelated = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت عن الطلب'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $owner->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $owner->notifications()->first();
        $this->assertEquals('spare_parts_order_cancelled_by_customer', $notification->type);
        $this->assertEquals('cancelled', $notification->data['data']['status']);
        $this->assertEquals('تراجعت عن الطلب', $notification->data['data']['reason']);
    }

    public function test_e7_customer_cancel_from_accepted_notifies_shop_owner(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'accepted');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت عن الطلب'])
            ->assertOk();

        $this->assertEquals(1, $owner->notifications()->count());
        $this->assertEquals('spare_parts_order_cancelled_by_customer', $owner->notifications()->first()->type);
    }

    public function test_second_cancel_attempt_creates_no_additional_notification(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'السبب الأول'])
            ->assertOk();
        $this->assertEquals(1, $owner->notifications()->count());

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'محاولة ثانية'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, $owner->notifications()->count());
    }

    public function test_disallowed_transition_creates_no_notification(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        Sanctum::actingAs($owner);

        // processing قبل accepted — انتقال غير مسموح
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals('pending', $order->fresh()->status);
    }

    public function test_notifications_skip_safely_when_shop_owner_soft_deleted(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        $owner->delete();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_e2_skips_safely_when_customer_soft_deleted(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');
        $customer->delete();
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_no_notification_on_read_or_cart_operations(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->getJson('/api/customer/shops')->assertOk();
        $this->getJson('/api/customer/products')->assertOk();
        $this->postJson('/api/customer/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->getJson('/api/customer/cart')->assertOk();
        $this->getJson('/api/customer/orders')->assertOk();

        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_accept_succeeds_when_notification_broadcast_fails(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $order = $this->makeOrderWithItems($owner, $customer, $product, 2, 'pending');

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }
}
