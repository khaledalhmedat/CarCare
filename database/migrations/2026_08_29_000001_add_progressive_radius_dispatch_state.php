<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_radius_km')->nullable()->after('city');
            $table->timestamp('radius_stage_started_at')->nullable()->after('current_radius_km');
        });

        Schema::table('sos_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_radius_km')->nullable()->after('city');
            $table->timestamp('radius_stage_started_at')->nullable()->after('current_radius_km');
        });

        Schema::create('dispatch_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('service_type');
            $table->unsignedBigInteger('request_id');
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id');
            $table->timestamp('notified_at');
            $table->unique(
                ['service_type', 'request_id', 'recipient_type', 'recipient_id'],
                'dispatch_recipient_unique'
            );
        });

        $maxRadiusKm = (int) config('dispatch.max_search_radius_km', 70);

        DB::table('fuel_orders')
            ->where('status', 'pending')
            ->whereNull('current_radius_km')
            ->update([
                'current_radius_km' => $maxRadiusKm,
                'radius_stage_started_at' => now(),
            ]);

        DB::table('sos_requests')
            ->where('status', 'open')
            ->whereNull('current_radius_km')
            ->update([
                'current_radius_km' => $maxRadiusKm,
                'radius_stage_started_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_notification_recipients');

        Schema::table('fuel_orders', function (Blueprint $table) {
            $table->dropColumn(['current_radius_km', 'radius_stage_started_at']);
        });

        Schema::table('sos_requests', function (Blueprint $table) {
            $table->dropColumn(['current_radius_km', 'radius_stage_started_at']);
        });
    }
};
