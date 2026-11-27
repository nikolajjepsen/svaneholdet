<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['slots', 'slot_series'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->boolean('is_admin_only')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['slots', 'slot_series'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('is_admin_only');
            });
        }
    }
};
