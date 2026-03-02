<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('stores', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('store_name');
    $table->string('commercial_register')->unique(); 
    $table->string('tax_number')->nullable();
    $table->string('phone')->nullable();
    $table->text('address');
    $table->string('city');
    $table->decimal('latitude', 10, 7)->nullable(); 
    $table->decimal('longitude', 10, 7)->nullable();
    $table->string('logo')->nullable();
    $table->text('description')->nullable();
    $table->boolean('is_verified')->default(false); 
    $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
    $table->timestamps();
    $table->softDeletes();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
