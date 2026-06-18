<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('discount_price', 12, 2)->nullable();
            $table->integer('discount_percent')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->string('dimensions')->nullable();
            
            $table->foreignId('car_brand_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('part_category_id')->nullable()->constrained()->onDelete('set null');
            
            $table->enum('condition', ['new', 'used'])->default('new');
            $table->boolean('is_featured')->default(false);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};