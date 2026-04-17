<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->after('is_available');
            $table->decimal('average_rating', 3, 2)->default(0)->after('is_verified');
            $table->integer('ratings_count')->default(0)->after('average_rating');
            $table->text('description')->nullable()->after('prices');
            $table->json('working_hours')->nullable()->after('description'); 
            // {'sat': '09:00-18:00', 'sun': '10:00-16:00'}
        });
    }

    public function down(): void
    {
        Schema::table('car_washers', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'average_rating', 'ratings_count', 'description', 'working_hours']);
        });
    }
};