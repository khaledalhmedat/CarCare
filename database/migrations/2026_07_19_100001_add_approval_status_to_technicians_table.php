<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            if (!Schema::hasColumn('technicians', 'status')) {
                $table->string('status')->default('pending')->after('is_available');
            }
            if (!Schema::hasColumn('technicians', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('technicians', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('technicians', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('technicians', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $columns = ['status', 'rejection_reason', 'approved_at', 'rejected_at', 'suspended_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('technicians', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
