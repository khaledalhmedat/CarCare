<?php

// للتذكير: هذا الملف يضيف عمود الفني إلى نقاط التتبع بشكل آمن غير مدمّر.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            if (!Schema::hasColumn('tracking_points', 'technician_id')) {
                $table->unsignedBigInteger('technician_id')->nullable()->after('sos_request_id');
                $table->index('technician_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracking_points', function (Blueprint $table) {
            if (Schema::hasColumn('tracking_points', 'technician_id')) {
                $table->dropIndex(['technician_id']);
                $table->dropColumn('technician_id');
            }
        });
    }
};
