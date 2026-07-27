<?php

// للتذكير: هذا الملف يضيف فهارس لتحسين أداء التقارير والفواتير والإشعارات وقوائم المزودين.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $indexes = [
        ['maintenance_requests', ['status', 'created_at'], 'mr_status_created_index'],
        ['sos_requests',         ['status', 'created_at'], 'sos_status_created_index'],
        ['fuel_orders',          ['status', 'created_at'], 'fo_status_created_index'],
        ['carwash_bookings',     ['status', 'created_at'], 'cb_status_created_index'],
        ['orders',               ['status', 'created_at'], 'orders_status_created_index'],
        ['technicians',    ['status'], 'tech_status_index'],
        ['car_washers',    ['status'], 'cw_status_index'],
        ['fuel_providers', ['status'], 'fp_status_index'],
        ['shops',          ['status'], 'shops_status_index'],
        ['notifications', ['notifiable_type', 'notifiable_id', 'read_at'], 'notif_read_index'],
        ['provider_invoices', ['status', 'due_at'], 'pi_status_due_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (!$this->tableColumnsExist($table, $columns)) {
                continue;
            }
            if ($this->indexExists($table, $name)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                $t->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (Schema::hasTable($table) && $this->indexExists($table, $name)) {
                Schema::table($table, function (Blueprint $t) use ($name) {
                    $t->dropIndex($name);
                });
            }
        }
    }

    private function tableColumnsExist(string $table, array $columns): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }
        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) {
                return false;
            }
        }
        return true;
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
