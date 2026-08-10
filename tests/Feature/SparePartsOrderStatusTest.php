<?php

// للتذكير: هذا الملف يختبر انتقالات حالة طلب قطع الغيار واستعادة المخزون تحت القفل.
// الاختبارات هنا تسلسلية داخل عملية واحدة وتثبت الثبات المنطقي (invariants) والنتائج النهائية فقط؛
// وهي لا تحاكي تزامناً حقيقياً بين طلبين HTTP متوازيين. الحماية الفعلية من السباقات الحقيقية
// تأتي من lockForUpdate() على مستوى قاعدة البيانات (MySQL)، وليس من ترتيب الاستدعاءات في PHPUnit.

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SparePartsOrderStatusTest extends TestCase
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

    /**
     * ينشئ طلباً مباشرة (بدون المرور بـcheckout) بحالة مطلوبة، مع عناصره ومخزون مُنقَص مسبقاً
     * ليطابق الحالة الواقعية التي كانتستنتج عن checkout فعلي بنفس الكميات.
     */
    private function makeOrderWithItems(User $owner, User $customer, array $itemsSpec, string $status, ?float $originalStock = null): array
    {
        $shop = Shop::where('user_id', $owner->id)->first();

        $totalPrice = 0;
        foreach ($itemsSpec as $spec) {
            $totalPrice += $spec['product']->price * $spec['quantity'];
        }

        $order = Order::create([
            'user_id' => $customer->id, 'shop_id' => $shop->id, 'total_price' => $totalPrice,
            'customer_latitude' => 33.54, 'customer_longitude' => 36.32, 'status' => $status,
        ]);

        foreach ($itemsSpec as $spec) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $spec['product']->id,
                'quantity' => $spec['quantity'], 'price' => $spec['product']->price,
            ]);
            $spec['product']->decrement('stock_quantity', $spec['quantity']);
        }

        return [$order, $shop];
    }

    public function test_pending_to_accepted_succeeds_and_stock_unchanged(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 2]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_pending_to_rejected_restores_stock_fully(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 3]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'نفدت الكمية'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals('نفدت الكمية', $order->fresh()->cancellation_reason);
    }

    public function test_customer_cancel_from_pending_restores_stock(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals('تراجعت', $order->fresh()->cancellation_reason);
    }

    public function test_customer_cancel_from_accepted_restores_stock(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'accepted');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals('تراجعت', $order->fresh()->cancellation_reason);
    }

    public function test_second_cancel_attempt_after_cancelled_keeps_original_reason_and_stock(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'السبب الأول'])
            ->assertOk();

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals('السبب الأول', $order->fresh()->cancellation_reason);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'محاولة ثانية مختلفة'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->assertEquals('السبب الأول', $order->fresh()->cancellation_reason);
        $this->assertEquals(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_reject_then_cancel_attempt_fails_no_double_restore(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'سبب الرفض'])->assertOk();
        $this->assertEquals(10, $product->fresh()->stock_quantity);

        Sanctum::actingAs($customer);
        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_cancel_then_reject_attempt_fails_no_double_restore(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])->assertOk();
        $this->assertEquals(10, $product->fresh()->stock_quantity);

        Sanctum::actingAs($owner);
        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'سبب الرفض'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_accept_then_customer_cancel_restores_stock_once(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")->assertOk();
        $this->assertEquals('accepted', $order->fresh()->status);
        $this->assertEquals(6, $product->fresh()->stock_quantity);

        Sanctum::actingAs($customer);
        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertOk();

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_accepted_to_processing_then_cancel_fails_stock_unrestored(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'accepted');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertEquals('processing', $order->fresh()->status);

        Sanctum::actingAs($customer);
        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'تراجعت'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('processing', $order->fresh()->status);
        $this->assertEquals(6, $product->fresh()->stock_quantity);
    }

    public function test_full_happy_path_to_delivered_keeps_stock_decremented(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 4]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/accept")->assertOk();
        $this->assertEquals('accepted', $order->fresh()->status);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
        $this->assertEquals('processing', $order->fresh()->status);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->assertEquals('out_for_delivery', $order->fresh()->status);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertEquals('delivered', $order->fresh()->status);

        $this->assertEquals(6, $product->fresh()->stock_quantity);
    }

    public function test_repeated_and_invalid_transitions_are_rejected(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 2]], 'pending');
        Sanctum::actingAs($owner);

        // accept مرتين
        $this->postJson("/api/shop/orders/{$order->id}/accept")->assertOk();
        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->assertEquals('accepted', $order->fresh()->status);

        // processing مرتين
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->assertEquals('processing', $order->fresh()->status);

        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();

        // delivered مرتين
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->assertEquals('delivered', $order->fresh()->status);

        // delivered -> processing (رجوع للخلف)
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->assertEquals('delivered', $order->fresh()->status);

        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertEquals(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_cancelled_to_accepted_is_rejected(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 2]], 'pending');
        Sanctum::actingAs($owner);

        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'سبب الرفض'])->assertOk();
        $this->assertEquals('cancelled', $order->fresh()->status);

        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    public function test_ownership_is_enforced_for_shop_and_customer_actions(): void
    {
        $owner = $this->makeApprovedShop();
        $otherOwner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10);
        $customer = $this->makeUser();
        $otherCustomer = $this->makeUser();
        [$order] = $this->makeOrderWithItems($owner, $customer, [['product' => $product, 'quantity' => 2]], 'pending');

        Sanctum::actingAs($otherOwner);
        $this->postJson("/api/shop/orders/{$order->id}/accept")
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'سبب الرفض'])
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->postJson("/api/shop/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(500)->assertJson(['success' => false]);

        Sanctum::actingAs($otherCustomer);
        $this->postJson("/api/customer/orders/{$order->id}/cancel", ['cancellation_reason' => 'محاولة'])
            ->assertStatus(500)->assertJson(['success' => false]);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_multiple_products_restore_exact_quantities_including_duplicate_order_items(): void
    {
        $owner = $this->makeApprovedShop();
        $productA = $this->makeProduct($owner, 10);
        $productB = $this->makeProduct($owner, 20);
        $customer = $this->makeUser();

        // عنصران منفصلان لنفس المنتج A (بيانات تاريخية محتملة) بالإضافة لمنتج B
        [$order, $shop] = $this->makeOrderWithItems($owner, $customer, [
            ['product' => $productA, 'quantity' => 2],
        ], 'pending');

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productA->id, 'quantity' => 3, 'price' => $productA->price,
        ]);
        $productA->decrement('stock_quantity', 3);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productB->id, 'quantity' => 5, 'price' => $productB->price,
        ]);
        $productB->decrement('stock_quantity', 5);

        $this->assertEquals(5, $productA->fresh()->stock_quantity);
        $this->assertEquals(15, $productB->fresh()->stock_quantity);

        Sanctum::actingAs($owner);
        $this->postJson("/api/shop/orders/{$order->id}/reject", ['reason' => 'سبب الرفض'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(10, $productA->fresh()->stock_quantity);
        $this->assertEquals(20, $productB->fresh()->stock_quantity);
    }
}
