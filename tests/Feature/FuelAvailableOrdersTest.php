<?php

// للتذكير: هذا الملف يختبر ظهور طلبات الوقود المتاحة لمزود الوقود حسب الحالة والمدينة والإحداثيات ونوع الوقود.

namespace Tests\Feature;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelAvailableOrdersTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private int $customerId;
    private int $customerVehicleId;

    private function actAsApprovedProvider(array $overrides = []): FuelProvider
    {
        $u = $this->makeUserWithRole('fuel-provider');
        $provider = FuelProvider::create(array_merge([
            'user_id' => $u->id, 'company_name' => 'FuelCo', 'phone' => '05', 'city' => 'دمشق',
            'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'latitude' => 33.5460, 'longitude' => 36.3249,
            'fuel_types' => ['95', '98', 'diesel'],
        ], $overrides));

        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'AV-' . uniqid(),
        ]);
        $this->customerId = $customer->id;
        $this->customerVehicleId = $vehicle->id;

        Sanctum::actingAs($u);

        return $provider;
    }

    private function makeOrder(array $attributes): FuelOrder
    {
        return FuelOrder::create(array_merge([
            'user_id' => $this->customerId,
            'vehicle_id' => $this->customerVehicleId,
            'fuel_type' => '95',
            'amount' => 20,
            'status' => 'pending',
            'delivery_address' => 'دمشق',
        ], $this->eligibleRadiusState(), $attributes));
    }

    private function availableIds(): array
    {
        $res = $this->getJson('/api/fuel_provider/available_orders')->assertOk();

        return array_map('intval', array_column($res->json('data'), 'id'));
    }

    public function test_pending_order_with_near_coords_appears(): void
    {
        $this->actAsApprovedProvider();
        $order = $this->makeOrder([
            'delivery_latitude' => 33.5460, 'delivery_longitude' => 36.3249,
        ]);

        $this->assertContains($order->id, $this->availableIds());
    }

    public function test_pending_order_with_null_coords_does_not_appear(): void
    {
        $this->actAsApprovedProvider();
        $order = $this->makeOrder([
            'delivery_latitude' => null, 'delivery_longitude' => null, 'city' => 'دمشق',
        ]);

        $this->assertNotContains($order->id, $this->availableIds());
    }

    public function test_order_with_unsupported_fuel_type_does_not_appear(): void
    {
        // المزود يوفر 98 و diesel فقط، والطلب من نوع 95 قريب جغرافياً
        $this->actAsApprovedProvider(['fuel_types' => ['98', 'diesel']]);
        $order = $this->makeOrder([
            'fuel_type' => '95',
            'delivery_latitude' => 33.5460, 'delivery_longitude' => 36.3249,
        ]);

        $this->assertNotContains($order->id, $this->availableIds());
    }

    public function test_accepted_or_completed_order_does_not_appear(): void
    {
        $this->actAsApprovedProvider();
        $accepted = $this->makeOrder([
            'status' => 'accepted',
            'delivery_latitude' => 33.5460, 'delivery_longitude' => 36.3249,
        ]);
        $completed = $this->makeOrder([
            'status' => 'completed', 'city' => 'دمشق',
            'delivery_latitude' => null, 'delivery_longitude' => null,
        ]);

        $ids = $this->availableIds();
        $this->assertNotContains($accepted->id, $ids);
        $this->assertNotContains($completed->id, $ids);
    }
}
