<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('status');
            $table->text('notes')->nullable()->after('scheduled_date');
            $table->timestamp('started_at')->nullable()->after('notes');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn(['scheduled_date', 'notes', 'started_at', 'completed_at']);
        });
    }
};