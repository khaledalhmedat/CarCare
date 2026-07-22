<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('sos_requests', 'technician_id')) {
                $table->foreignId('technician_id')
                    ->nullable()
                    ->after('vehicle_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sos_requests', function (Blueprint $table) {
            if (Schema::hasColumn('sos_requests', 'technician_id')) {
                $table->dropForeign(['technician_id']);
                $table->dropColumn('technician_id');
            }
        });
    }
};
