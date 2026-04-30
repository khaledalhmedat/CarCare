<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            $table->text('description')->nullable()->after('lng');
            $table->foreignId('technician_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('priority')->default('emergency')->after('status');
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('accepted_at');
            $table->text('cancellation_reason')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            $table->dropColumn(['description', 'technician_id', 'priority', 'accepted_at', 'completed_at', 'cancellation_reason']);
        });
    }
};