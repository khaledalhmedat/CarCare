<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_invoice_id')->constrained('provider_invoices')->cascadeOnDelete();
            $table->string('item_type'); // monthly_subscription | commission | adjustment | free_trial
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index('provider_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_invoice_items');
    }
};
