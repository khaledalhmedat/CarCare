<?php

// للتذكير: هذا الملف يختبر سلامة إنشاء طلب قطع الغيار والمخزون تحت القفل.
// الاختبارات هنا تسلسلية داخل عملية واحدة وتثبت الثبات المنطقي (invariants) والنتائج النهائية فقط؛
// وهي لا تحاكي تزامناً حقيقياً بين طلبين HTTP متوازيين. الحماية الفعلية من السباقات الحقيقية
// تأتي من lockForUpdate() على مستوى قاعدة البيانات (MySQL)، وليس من ترتيب الاستدعاءات في PHPUnit.

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\CustomerShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SparePartsOrderIntegrityTest extends TestCase
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

    public function test_normal_checkout_creates_order_and_decrements_stock_once(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10, 100);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 2);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, Order::count());
        $order = Order::first();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(200, $order->total_price);

        $this->assertEquals(1, OrderItem::where('order_id', $order->id)->count());
        $item = OrderItem::where('order_id', $order->id)->first();
        $this->assertEquals(2, $item->quantity);
        $this->assertEquals(100, $item->price);

        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertEquals(0, Cart::where('user_id', $customer->id)->count());

        $response->assertJsonStructure([
            'data' => ['id', 'shop', 'items', 'total_price', 'status', 'status_text', 'can_cancel'],
        ]);
    }

    public function test_multi_shop_cart_checkout_fails_with_no_mutation(): void
    {
        $owner1 = $this->makeApprovedShop();
        $owner2 = $this->makeApprovedShop();
        $product1 = $this->makeProduct($owner1, 10, 100);
        $product2 = $this->makeProduct($owner2, 10, 50);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product1, 1);
        $this->addToCart($customer, $product2, 1);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(10, $product1->fresh()->stock_quantity);
        $this->assertEquals(10, $product2->fresh()->stock_quantity);
        $this->assertEquals(2, Cart::where('user_id', $customer->id)->count());

        // تحقق من الرسالة الحرفية عبر استدعاء الخدمة مباشرة، لأن معالج الأخطاء العام
        // في الـController يستبدل الرسالة التفصيلية برسالة عامة عند إرجاع 500 عبر HTTP.
        try {
            app(CustomerShopService::class)->createOrder($customer, $this->checkoutPayload());
            $this->fail('كان يجب أن يفشل الطلب.');
        } catch (\Exception $e) {
            $this->assertEquals('لا يمكن شراء منتجات من متاجر مختلفة في طلب واحد', $e->getMessage());
        }
    }

    public function test_insufficient_stock_checkout_fails_with_no_mutation(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 1, 100);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 5);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(1, $product->fresh()->stock_quantity);
        $this->assertEquals(1, Cart::where('user_id', $customer->id)->count());
    }

    public function test_later_item_failure_causes_no_partial_mutation(): void
    {
        $owner = $this->makeApprovedShop();
        $productOk = $this->makeProduct($owner, 10, 100);
        $productShort = $this->makeProduct($owner, 1, 50);
        $customer = $this->makeUser();
        $this->addToCart($customer, $productOk, 2);
        $this->addToCart($customer, $productShort, 5);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(10, $productOk->fresh()->stock_quantity);
        $this->assertEquals(1, $productShort->fresh()->stock_quantity);
        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(2, Cart::where('user_id', $customer->id)->count());
    }

    public function test_second_checkout_from_same_cart_after_success_fails(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10, 100);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 2);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/orders', $this->checkoutPayload())->assertCreated();

        // محاولة ثانية من نفس المستخدم بعد نجاح الأولى — السلة أصبحت فارغة الآن،
        // فهذا تسلسل داخل نفس العملية وليس اختبار تزامن حقيقي؛ lockForUpdate هو ضمان السباق الحقيقي.
        $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, Order::count());
        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->assertEquals(1, OrderItem::count());
    }

    public function test_shop_resource_returns_full_owner_for_normal_shop(): void
    {
        $owner = $this->makeApprovedShop();
        $product = $this->makeProduct($owner, 10, 100);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 1);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/customer/orders', $this->checkoutPayload())->assertCreated();

        $owner->refresh();
        $response->assertJson([
            'data' => [
                'shop' => [
                    'owner' => [
                        'id' => $owner->id,
                        'name' => $owner->name,
                        'email' => $owner->email,
                    ],
                ],
            ],
        ]);
    }

    public function test_shop_owner_soft_deleted_produces_null_owner_without_500(): void
    {
        // createOrder لا يتحقق من حالة المتجر أو وجود مالكه إطلاقاً (لا شرط مستقل يمنع هذا المسار)،
        // لذا يمكن اختبار الإصلاح عبر HTTP الحقيقي مباشرة: نحذف المالك حذفاً ناعماً قبل الحجز،
        // ثم ننفذ Checkout كاملاً ونتحقق أن الاستجابة لا تنهار وأن owner تصبح null.
        $owner = $this->makeApprovedShop();
        $shop = Shop::where('user_id', $owner->id)->first();
        $product = $this->makeProduct($owner, 10, 100);
        $customer = $this->makeUser();
        $this->addToCart($customer, $product, 1);

        $owner->delete();

        Sanctum::actingAs($customer);
        $response = $this->postJson('/api/customer/orders', $this->checkoutPayload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $response->assertJsonPath('data.shop.owner', null);
        $response->assertJsonPath('data.shop.id', $shop->id);
        $response->assertJsonPath('data.shop.name', 'AutoParts');
        $this->assertEquals(1, Order::count());
    }
}
