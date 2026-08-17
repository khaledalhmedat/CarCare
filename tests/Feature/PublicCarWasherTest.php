<?php

// للتذكير: هذا الملف يختبر العقد العام لقائمة/تفاصيل المغاسل ومسار تقييماتها (بدون كشف حقول داخلية).

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarWashRating;
use App\Models\CarwashBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class PublicCarWasherTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeCarWasher(array $overrides = []): CarWasher
    {
        $washerUser = $this->makeUserWithRole('car-washer');

        return CarWasher::create(array_merge([
            'user_id' => $washerUser->id,
            'shop_name' => 'CleanCo',
            'phone' => '05',
            'city' => 'دمشق',
            'address' => 'x',
            'latitude' => 33.5,
            'longitude' => 36.3,
            'status' => 'approved',
            'is_available' => true,
            'is_verified' => true,
            'service_prices' => ['basic' => 10],
            'average_rating' => 4.3,
            'ratings_count' => 17,
            'rejection_reason' => 'سبب داخلي حساس',
        ], $overrides));
    }

    private function makeRating(CarWasher $carWasher, ?User $reviewer, string $review = 'ممتاز'): CarWashRating
    {
        $customer = $reviewer ?? $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2021, 'plate_number' => 'PB-' . uniqid(),
        ]);
        $booking = CarwashBooking::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'car_washer_id' => $carWasher->id, 'scheduled_at' => now()->addDay(),
            'service_type' => 'basic', 'price' => 10, 'status' => 'completed',
        ]);

        return CarWashRating::create([
            'user_id' => $customer->id,
            'carwash_booking_id' => $booking->id,
            'car_washer_id' => $carWasher->id,
            'rating' => 4,
            'review' => $review,
        ]);
    }

    public function test_list_returns_car_washer_id_not_user_id(): void
    {
        // create throwaway users first so the washer's user_id and the car_washer's own id
        // are guaranteed to diverge (both auto-increment PKs otherwise start at 1 independently)
        $this->makeUser();
        $this->makeUser();
        $carWasher = $this->makeCarWasher();
        $customer = $this->makeUser();
        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/customer/car_washers')->assertOk();
        $item = $response->json('data.data.0');

        $this->assertEquals($carWasher->id, $item['id']);
        $this->assertNotEquals($carWasher->user_id, $item['id']);
    }

    public function test_list_does_not_expose_user_id_or_user(): void
    {
        $this->makeCarWasher();
        Sanctum::actingAs($this->makeUser());

        $item = $this->getJson('/api/customer/car_washers')->assertOk()->json('data.data.0');

        $this->assertArrayNotHasKey('user_id', $item);
        $this->assertArrayNotHasKey('user', $item);
    }

    public function test_list_does_not_expose_admin_lifecycle_fields(): void
    {
        $this->makeCarWasher();
        Sanctum::actingAs($this->makeUser());

        $item = $this->getJson('/api/customer/car_washers')->assertOk()->json('data.data.0');

        foreach (['status', 'rejection_reason', 'approved_at', 'rejected_at', 'suspended_at'] as $field) {
            $this->assertArrayNotHasKey($field, $item);
        }
    }

    public function test_list_preserves_pagination_envelope(): void
    {
        $this->makeCarWasher();
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson('/api/customer/car_washers')->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => ['current_page', 'data', 'per_page', 'total'],
            'meta' => ['total', 'per_page', 'current_page'],
        ]);
        $this->assertIsArray($response->json('data.data'));
    }

    public function test_details_use_same_public_fields(): void
    {
        $carWasher = $this->makeCarWasher();
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson("/api/customer/car_washers/{$carWasher->id}")->assertOk();
        $data = $response->json('data');

        $this->assertEquals($carWasher->id, $data['id']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('rejection_reason', $data);
        $this->assertIsFloat($data['average_rating']);
        $this->assertIsInt($data['ratings_count']);
    }

    public function test_nonexistent_car_washer_details_returns_404(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/customer/car_washers/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_ratings_meta_reflects_car_washer_record_when_ratings_exist(): void
    {
        $carWasher = $this->makeCarWasher(['average_rating' => 4.3, 'ratings_count' => 17]);
        $this->makeRating($carWasher, $this->makeUser());
        Sanctum::actingAs($this->makeUser());

        $meta = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk()->json('meta');

        $this->assertEquals(4.3, $meta['average_rating']);
        $this->assertEquals(17, $meta['total_ratings']);
    }

    public function test_ratings_meta_is_not_zero_when_ratings_list_is_empty(): void
    {
        $carWasher = $this->makeCarWasher(['average_rating' => 4.3, 'ratings_count' => 17]);
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk();

        $this->assertEquals([], $response->json('data'));
        $this->assertEquals(4.3, $response->json('meta.average_rating'));
        $this->assertEquals(17, $response->json('meta.total_ratings'));
    }

    public function test_ratings_out_of_range_page_returns_empty_data_with_correct_meta(): void
    {
        $carWasher = $this->makeCarWasher(['average_rating' => 4.3, 'ratings_count' => 17]);
        $this->makeRating($carWasher, $this->makeUser());
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings?page=99")->assertOk();

        $this->assertEquals([], $response->json('data'));
        $this->assertEquals(4.3, $response->json('meta.average_rating'));
        $this->assertEquals(17, $response->json('meta.total_ratings'));
    }

    public function test_ratings_count_is_integer_and_average_is_numeric(): void
    {
        $carWasher = $this->makeCarWasher(['average_rating' => 4.3, 'ratings_count' => 17]);
        Sanctum::actingAs($this->makeUser());

        $meta = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk()->json('meta');

        $this->assertIsInt($meta['total_ratings']);
        $this->assertIsFloat($meta['average_rating']);
    }

    public function test_rating_with_existing_reviewer_returns_user_in_current_shape(): void
    {
        $carWasher = $this->makeCarWasher();
        $reviewer = $this->makeUser(['name' => 'Reviewer Name']);
        $this->makeRating($carWasher, $reviewer);
        Sanctum::actingAs($this->makeUser());

        $item = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk()->json('data.0');

        $this->assertEquals($reviewer->id, $item['user']['id']);
        $this->assertEquals('Reviewer Name', $item['user']['name']);
    }

    public function test_rating_with_soft_deleted_reviewer_returns_null_user_without_500(): void
    {
        $carWasher = $this->makeCarWasher();
        $reviewer = $this->makeUser();
        $this->makeRating($carWasher, $reviewer);
        $reviewer->delete();
        Sanctum::actingAs($this->makeUser());

        $response = $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk();

        $this->assertNull($response->json('data.0.user'));
    }

    public function test_ratings_endpoint_does_not_trigger_obvious_n_plus_one(): void
    {
        $carWasher = $this->makeCarWasher();
        for ($i = 0; $i < 5; $i++) {
            $this->makeRating($carWasher, $this->makeUser());
        }
        Sanctum::actingAs($this->makeUser());

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson("/api/customer/car_washers/{$carWasher->id}/ratings")->assertOk();

        // fixed number of queries regardless of rating count (no per-row user lookup)
        $this->assertLessThan(10, $queries);
    }

    public function test_private_car_washer_profile_response_is_unchanged(): void
    {
        $washerUser = $this->makeUserWithRole('car-washer');
        CarWasher::create([
            'user_id' => $washerUser->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'service_prices' => ['basic' => 10],
        ]);
        Sanctum::actingAs($washerUser);

        $data = $this->getJson('/api/car_washer/my_profile')->assertOk()->json('data');

        // private profile keeps exposing ownership/lifecycle fields via CarWasherResource — unaffected by the new public resource
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('rejection_reason', $data);
        $this->assertArrayHasKey('approved_at', $data);
    }
}
