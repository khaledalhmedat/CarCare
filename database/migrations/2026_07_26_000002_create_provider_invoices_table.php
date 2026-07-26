<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->string('provider_type');
            $table->unsignedBigInteger('provider_id');
            $table->foreignId('billing_setting_id')->nullable()
                ->constrained('provider_billing_settings')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('commission_total', 12, 2)->default(0);
            $table->decimal('subscription_total', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            // stored status: draft | issued | paid | cancelled  (overdue is computed dynamically)
            $table->string('status')->default('draft');
            $table->string('external_payment_method')->nullable();
            $table->string('external_payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['provider_type', 'provider_id']);
            $table->index('status');
            // one invoice per provider per exact period — duplicate prevention
            $table->unique(['provider_type', 'provider_id', 'period_start', 'period_end'], 'provider_invoice_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_invoices');
    }
};
