<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->float('average_rating')->default(0)->after('is_available');
            $table->integer('ratings_count')->default(0)->after('average_rating');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn(['average_rating', 'ratings_count']);
        });
    }
};