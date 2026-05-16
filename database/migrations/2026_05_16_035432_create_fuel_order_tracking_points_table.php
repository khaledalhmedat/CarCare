<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_order_tracking_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('fuel_provider_id')->constrained()->onDelete('cascade');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_order_tracking_points');
    }
};