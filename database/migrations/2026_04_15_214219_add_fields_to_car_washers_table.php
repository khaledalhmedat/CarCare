<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            if (!Schema::hasColumn('car_washers', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }
            if (!Schema::hasColumn('car_washers', 'average_rating')) {
                $table->decimal('average_rating', 3, 2)->default(0);
            }
            if (!Schema::hasColumn('car_washers', 'ratings_count')) {
                $table->integer('ratings_count')->default(0);
            }
            if (!Schema::hasColumn('car_washers', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('car_washers', 'working_hours')) {
                $table->json('working_hours')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            $columns = ['is_verified', 'average_rating', 'ratings_count', 'description', 'working_hours'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('car_washers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};