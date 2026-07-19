<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('fuel_providers', 'status')) {
                $table->string('status')->default('pending')->after('is_verified');
            }
            if (!Schema::hasColumn('fuel_providers', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('fuel_providers', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('fuel_providers', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('fuel_providers', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fuel_providers', function (Blueprint $table) {
            $columns = ['status', 'rejection_reason', 'approved_at', 'rejected_at', 'suspended_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('fuel_providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
