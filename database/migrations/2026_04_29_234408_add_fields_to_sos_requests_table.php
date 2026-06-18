<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            if (Schema::hasColumn('sos_requests', 'technician_id')) {
                $table->dropForeign(['technician_id']);
            }
            
            if (Schema::hasColumn('sos_requests', 'technician_id')) {
                $table->foreignId('technician_id')->nullable()->change();
            }
            
            if (!Schema::hasColumn('sos_requests', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('sos_requests', 'priority')) {
                $table->string('priority')->default('emergency');
            }
            if (!Schema::hasColumn('sos_requests', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable();
            }
            if (!Schema::hasColumn('sos_requests', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (!Schema::hasColumn('sos_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'priority',
                'accepted_at',
                'completed_at',
                'cancellation_reason'
            ]);
        });
    }
};