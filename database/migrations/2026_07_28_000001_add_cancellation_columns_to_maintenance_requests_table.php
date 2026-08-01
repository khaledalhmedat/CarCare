<?php

// للتذكير: هذا الملف يضيف أعمدة الإلغاء إلى طلبات الصيانة بشكل آمن غير مدمّر.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('maintenance_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (!Schema::hasColumn('maintenance_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            foreach (['cancellation_reason', 'cancelled_at'] as $column) {
                if (Schema::hasColumn('maintenance_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
