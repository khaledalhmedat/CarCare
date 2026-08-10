<?php

// للتذكير: هذا الملف يختبر انتقالات حالة طلب الوقود (idempotency) وقفل التزامن ومنع تكرار FuelLog.

namespace Tests\Feature;

use App\Models\FuelLog;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelOrderStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeApprovedProvider(): User
    {
        $providerUser = $this->makeUserWithRole('fuel-provider');
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'fuel_types' => ['95', '98', 'diesel'],
        ]);

        return $providerUser;
    }

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FS-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makeOrderForProvider(User $providerUser, string $status = 'accepted'): FuelOrder
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $fuelProvider = FuelProvider::where('user_id', $providerUser->id)->first();

        return FuelOrder::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => '95', 'amount' => 20, 'delivery_address' => 'دمشق',
            'status' => $status, 'fuel_provider_id' => $fuelProvider->id,
            'total_price' => 50, 'accepted_at' => now(),
        ]);
    }

    public function test_accepted_to_in_progress_succeeds(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('in_progress', $order->fresh()->status);
    }

    public function test_accepted_to_completed_remains_supported(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('completed', $order->fresh()->status);
        $this->assertEquals(1, FuelLog::where('fuel_order_id', $order->id)->count());
    }

    public function test_in_progress_to_completed_succeeds(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'in_progress');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_repeated_in_progress_is_rejected(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        // controller هذا الإجراء لا يغلّف الاستثناء بـ try/catch، فتُعالجه معالجة الأخطاء العامة وتُرجع 500
        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('in_progress', $order->fresh()->status);
    }

    public function test_repeated_completed_is_rejected(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_completed_to_in_progress_is_rejected(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'completed');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_first_completion_creates_exactly_one_fuel_log(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'in_progress');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertEquals(1, FuelLog::where('fuel_order_id', $order->id)->count());
    }

    public function test_repeated_completed_does_not_create_second_fuel_log(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'in_progress');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(500);

        $this->assertEquals(1, FuelLog::where('fuel_order_id', $order->id)->count());
    }

    public function test_unauthorized_provider_cannot_update_others_order(): void
    {
        $owner = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($owner, 'accepted');
        $other = $this->makeApprovedProvider();
        Sanctum::actingAs($other);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('accepted', $order->fresh()->status);
    }

    public function test_valid_transition_succeeds_when_operational_broadcast_fails(): void
    {
        $provider = $this->makeApprovedProvider();
        $order = $this->makeOrderForProvider($provider, 'accepted');

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('in_progress', $order->fresh()->status);
    }
}
