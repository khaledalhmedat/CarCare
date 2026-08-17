<?php

// للتذكير: هذا الملف يختبر عرض مزود الخدمة لفواتيره الخاصة فقط (قراءة فقط، بلا أي تعديل).

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\FuelProvider;
use App\Models\ProviderInvoice;
use App\Models\ProviderInvoiceItem;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ProviderInvoiceReadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeTechnicianUser(): array
    {
        $user = $this->makeUserWithRole('technician');
        $technician = Technician::create([
            'user_id' => $user->id, 'specialization' => 'general', 'experience_years' => 3,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return [$user, $technician->id];
    }

    private function makeCarWasherUser(): array
    {
        $user = $this->makeUserWithRole('car-washer');
        $carWasher = CarWasher::create([
            'user_id' => $user->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
        ]);

        return [$user, $carWasher->id];
    }

    private function makeFuelProviderUser(): array
    {
        $user = $this->makeUserWithRole('fuel-provider');
        $fuelProvider = FuelProvider::create([
            'user_id' => $user->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
        ]);

        return [$user, $fuelProvider->id];
    }

    private function makeShopOwnerUser(): array
    {
        $user = $this->makeUserWithRole('shop-owner');
        $shop = Shop::create([
            'user_id' => $user->id, 'name' => 'AutoParts', 'phone' => '05',
            'city' => 'دمشق', 'is_active' => true, 'status' => 'approved',
        ]);

        return [$user, $shop->id];
    }

    private function makeInvoice(string $providerType, int $providerId, array $overrides = []): ProviderInvoice
    {
        return ProviderInvoice::create(array_merge([
            'invoice_number' => 'INV-' . uniqid(),
            'provider_type' => $providerType,
            'provider_id' => $providerId,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'subtotal' => 100, 'commission_total' => 20, 'subscription_total' => 80,
            'total_amount' => 100, 'status' => 'issued',
            'issued_at' => now(), 'due_at' => now()->subDay(), // في الماضي => overdue
        ], $overrides));
    }

    private function removeRole(User $user, string $slug): void
    {
        $role = Role::where('slug', $slug)->first();
        if ($role) {
            $user->roles()->detach($role->id);
        }
    }

    public function test_fuel_provider_profile_without_matching_role_is_forbidden(): void
    {
        [$fuelUser, $fuelId] = $this->makeFuelProviderUser();
        $this->makeInvoice('fuel-provider', $fuelId);
        $this->removeRole($fuelUser, 'fuel-provider');
        Sanctum::actingAs($fuelUser);

        $this->getJson('/api/provider/invoices')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_shop_profile_without_shop_owner_role_is_forbidden(): void
    {
        [$shopUser, $shopId] = $this->makeShopOwnerUser();
        $this->makeInvoice('shop', $shopId);
        $this->removeRole($shopUser, 'shop-owner');
        Sanctum::actingAs($shopUser);

        $this->getJson('/api/provider/invoices')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_mismatched_provider_role_grants_no_access(): void
    {
        // مستخدم يملك دور shop-owner لكن ملفه الفعلي هو Technician فقط — لا تطابق بين الدور والملف
        $user = $this->makeUserWithRole('shop-owner');
        Technician::create([
            'user_id' => $user->id, 'specialization' => 'general', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/provider/invoices')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_correct_role_and_profile_pair_keeps_working(): void
    {
        [$fuelUser, $fuelId] = $this->makeFuelProviderUser();
        $mine = $this->makeInvoice('fuel-provider', $fuelId);
        Sanctum::actingAs($fuelUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk()->assertJson(['success' => true]);
        $this->assertEquals([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_list_does_not_show_items_key_when_not_eager_loaded(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $invoice = $this->makeInvoice('technician', $techId);
        ProviderInvoiceItem::create([
            'provider_invoice_id' => $invoice->id, 'item_type' => 'commission',
            'description' => 'عمولة', 'amount' => 10,
        ]);
        Sanctum::actingAs($techUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk();

        // القائمة لا تحمّل items فعلياً، فيجب ألا يظهر مفتاح items إطلاقاً (لا [] كاذبة)
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
    }

    public function test_unauthenticated_cannot_list_invoices(): void
    {
        $this->getJson('/api/provider/invoices')->assertUnauthorized();
    }

    public function test_customer_without_provider_profile_is_forbidden(): void
    {
        $customer = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->getJson('/api/provider/invoices')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_technician_sees_only_own_invoices(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $mine = $this->makeInvoice('technician', $techId);

        [, $otherTechId] = $this->makeTechnicianUser();
        $this->makeInvoice('technician', $otherTechId);

        Sanctum::actingAs($techUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk()->assertJson(['success' => true]);
        $ids = array_column($response->json('data'), 'id');

        $this->assertEquals([$mine->id], $ids);
    }

    public function test_car_washer_sees_only_own_invoices(): void
    {
        [$washerUser, $washerId] = $this->makeCarWasherUser();
        $mine = $this->makeInvoice('car-washer', $washerId);
        Sanctum::actingAs($washerUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk();
        $this->assertEquals([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_fuel_provider_sees_only_own_invoices(): void
    {
        [$fuelUser, $fuelId] = $this->makeFuelProviderUser();
        $mine = $this->makeInvoice('fuel-provider', $fuelId);
        Sanctum::actingAs($fuelUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk();
        $this->assertEquals([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_shop_owner_sees_only_own_invoices(): void
    {
        [$shopUser, $shopId] = $this->makeShopOwnerUser();
        $mine = $this->makeInvoice('shop', $shopId);
        Sanctum::actingAs($shopUser);

        $response = $this->getJson('/api/provider/invoices')->assertOk();
        $this->assertEquals([$mine->id], array_column($response->json('data'), 'id'));
    }

    public function test_provider_cannot_see_other_provider_of_same_type_invoice(): void
    {
        [$techUser] = $this->makeTechnicianUser();
        [, $otherTechId] = $this->makeTechnicianUser();
        $otherInvoice = $this->makeInvoice('technician', $otherTechId);
        Sanctum::actingAs($techUser);

        $this->getJson("/api/provider/invoices/{$otherInvoice->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_provider_cannot_see_other_provider_type_invoice(): void
    {
        [$techUser] = $this->makeTechnicianUser();
        [, $shopId] = $this->makeShopOwnerUser();
        $shopInvoice = $this->makeInvoice('shop', $shopId);
        Sanctum::actingAs($techUser);

        $this->getJson("/api/provider/invoices/{$shopInvoice->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_owned_invoice_details_work_with_items(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $invoice = $this->makeInvoice('technician', $techId);
        ProviderInvoiceItem::create([
            'provider_invoice_id' => $invoice->id, 'item_type' => 'commission',
            'description' => 'عمولة على الطلبات', 'amount' => 20,
        ]);
        Sanctum::actingAs($techUser);

        $response = $this->getJson("/api/provider/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals($invoice->id, $response->json('data.id'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals('عمولة على الطلبات', $response->json('data.items.0.description'));
    }

    public function test_unowned_invoice_details_returns_404(): void
    {
        [$techUser] = $this->makeTechnicianUser();
        [, $otherTechId] = $this->makeTechnicianUser();
        $otherInvoice = $this->makeInvoice('technician', $otherTechId);
        Sanctum::actingAs($techUser);

        $this->getJson("/api/provider/invoices/{$otherInvoice->id}")->assertStatus(404);
    }

    public function test_list_ignores_injected_provider_id_and_type_query_params(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $mine = $this->makeInvoice('technician', $techId);

        [, $shopId] = $this->makeShopOwnerUser();
        $notMine = $this->makeInvoice('shop', $shopId);

        Sanctum::actingAs($techUser);

        // محاولة تمرير هوية مزود آخر عبر الاستعلام يجب ألا يغيّر النتيجة إطلاقاً
        $response = $this->getJson('/api/provider/invoices?provider_type=shop&provider_id=' . $shopId)
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertEquals([$mine->id], $ids);
        $this->assertNotContains($notMine->id, $ids);
    }

    public function test_overdue_reflects_existing_contract(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $invoice = $this->makeInvoice('technician', $techId, [
            'status' => 'issued', 'due_at' => now()->subDays(2),
        ]);
        Sanctum::actingAs($techUser);

        $response = $this->getJson("/api/provider/invoices/{$invoice->id}")->assertOk();

        $this->assertEquals('issued', $response->json('data.status'));
        $this->assertEquals('overdue', $response->json('data.effective_status'));
    }

    public function test_no_mutating_provider_invoice_routes_exist(): void
    {
        [$techUser, $techId] = $this->makeTechnicianUser();
        $invoice = $this->makeInvoice('technician', $techId);
        Sanctum::actingAs($techUser);

        $this->postJson("/api/provider/invoices/{$invoice->id}/mark-paid", [])->assertStatus(404);
        $this->postJson("/api/provider/invoices/{$invoice->id}/issue")->assertStatus(404);
        // "generate" يطابق نمط GET /provider/invoices/{id} كمعرّف، فيُرفض بـ405 (POST غير مسموحة) لا 404 — يثبت غياب أي مسار تعديل بنفس القوة
        $this->postJson('/api/provider/invoices/generate', [])->assertStatus(405);
    }
}
