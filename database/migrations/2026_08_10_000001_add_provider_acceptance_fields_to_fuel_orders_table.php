<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('fuel_orders', 'estimated_arrival_minutes')) {
                $table->unsignedSmallInteger('estimated_arrival_minutes')->nullable()->after('accepted_at');
            }
            if (!Schema::hasColumn('fuel_orders', 'provider_notes')) {
                $table->string('provider_notes', 500)->nullable()->after('estimated_arrival_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fuel_orders', function (Blueprint $table) {
            $columns = ['estimated_arrival_minutes', 'provider_notes'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('fuel_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
