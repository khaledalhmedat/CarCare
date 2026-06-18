<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            if (Schema::hasColumn('tracking_points', 'technician_id')) {
                $table->dropForeign(['technician_id']);
            }

            if (Schema::hasColumn('tracking_points', 'technician_id')) {
                $table->foreignId('technician_id')->nullable()->change();
            }

            if (Schema::hasColumn('tracking_points', 'heading')) {
                $table->dropColumn('heading');
            }
            if (Schema::hasColumn('tracking_points', 'speed')) {
                $table->dropColumn('speed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            if (! Schema::hasColumn('tracking_points', 'technician_id')) {
                $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('tracking_points', 'heading')) {
                $table->string('heading')->nullable();
            }
            if (! Schema::hasColumn('tracking_points', 'speed')) {
                $table->float('speed')->nullable();
            }
        });
    }
};
