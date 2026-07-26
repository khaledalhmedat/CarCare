<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type'); // technician | car-washer | fuel-provider | shop
            $table->unsignedBigInteger('provider_id'); // the provider PROFILE id (technicians.id, etc.)
            $table->string('billing_type'); // monthly_subscription | commission_per_order | subscription_plus_commission | exempt
            $table->decimal('monthly_fee', 12, 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->unsignedInteger('free_trial_days')->default(0);
            $table->unsignedInteger('payment_due_days')->default(7);
            $table->date('starts_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['provider_type', 'provider_id'], 'pbs_provider_index');
            $table->index(['provider_type', 'provider_id', 'is_active'], 'pbs_provider_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_billing_settings');
    }
};
