<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carwash_bookings', function (Blueprint $table) {
            $table->foreignId('car_washer_id')->nullable()->after('technician_id')->constrained()->nullOnDelete();
            $table->string('service_type')->default('basic')->after('scheduled_at');
            $table->decimal('price', 10, 2)->nullable()->after('service_type');
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('accepted_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->text('cancellation_reason')->nullable()->after('completed_at');
            $table->text('notes')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('carwash_bookings', function (Blueprint $table) {
            $table->dropForeign(['car_washer_id']);
            $table->dropColumn(['car_washer_id', 'service_type', 'price', 'accepted_at', 'started_at', 'completed_at', 'cancellation_reason', 'notes']);
        });
    }
};