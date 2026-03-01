<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('live_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sos_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_tracking_sessions');
    }
};
