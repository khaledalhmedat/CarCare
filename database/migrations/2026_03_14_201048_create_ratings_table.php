<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_job_id')->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->constrained('users')->onDelete('cascade');
            $table->tinyInteger('rating')->unsigned(); // 1-5
            $table->text('review')->nullable();
            $table->timestamps();
            
            $table->unique(['user_id', 'service_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};