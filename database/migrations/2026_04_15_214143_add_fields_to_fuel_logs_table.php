<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_logs', function (Blueprint $table) {
            $table->foreignId('fuel_order_id')->nullable()->after('vehicle_id')->constrained()->nullOnDelete();
            $table->string('fuel_type')->nullable()->after('amount');
            $table->foreignId('fuel_provider_id')->nullable()->after('fuel_type')->constrained()->nullOnDelete();
            $table->string('odometer_image')->nullable()->after('km_at_fill'); 
        });
    }

    public function down(): void
    {
        Schema::table('fuel_logs', function (Blueprint $table) {
            $table->dropForeign(['fuel_order_id']);
            $table->dropForeign(['fuel_provider_id']);
            $table->dropColumn(['fuel_order_id', 'fuel_type', 'fuel_provider_id', 'odometer_image']);
        });
    }
};