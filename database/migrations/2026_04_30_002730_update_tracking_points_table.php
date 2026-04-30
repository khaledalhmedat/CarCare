<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            $table->foreignId('technician_id')->after('sos_request_id')->constrained('users')->cascadeOnDelete();
            $table->string('heading')->nullable()->after('lng');
            $table->float('speed')->nullable()->after('heading');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            $table->dropColumn(['technician_id', 'heading', 'speed']);
        });
    }
};