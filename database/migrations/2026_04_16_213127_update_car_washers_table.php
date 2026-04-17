<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('shop_name');
            $table->json('service_prices')->nullable()->after('prices');
        });
    }

    public function down(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            $table->dropColumn(['logo', 'description', 'working_hours', 'service_prices', 'average_rating', 'ratings_count', 'is_verified']);
        });
    }
};