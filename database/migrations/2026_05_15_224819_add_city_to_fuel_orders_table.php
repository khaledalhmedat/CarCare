<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_orders', function (Blueprint $table) {
            $table->string('city')->nullable()->after('delivery_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_orders', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};