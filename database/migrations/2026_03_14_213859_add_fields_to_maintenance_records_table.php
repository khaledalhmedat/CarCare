<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('maintenance_records', 'parts_used')) {
                $table->json('parts_used')->nullable()->after('details');
            }
            if (!Schema::hasColumn('maintenance_records', 'completion_notes')) {
                $table->text('completion_notes')->nullable()->after('parts_used');
            }
            if (!Schema::hasColumn('maintenance_records', 'recommendations')) {
                $table->text('recommendations')->nullable()->after('completion_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->dropColumn(['parts_used', 'completion_notes', 'recommendations']);
        });
    }
};