<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            if (Schema::hasColumn('tracking_points', 'live_tracking_session_id')) {
                $table->dropForeign(['live_tracking_session_id']);
                $table->dropColumn('live_tracking_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            $table->foreignId('live_tracking_session_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};