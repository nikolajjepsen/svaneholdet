<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->string('attendance_status')->default('planned');
            $table->timestampTz('actual_starts_at')->nullable();
            $table->timestampTz('actual_ends_at')->nullable();
            $table->foreignId('attendance_recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('attendance_recorded_at')->nullable();
        });

        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_attendance_status_valid CHECK (attendance_status IN ('planned', 'adjusted', 'no_show'))");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_actual_times_valid CHECK ((attendance_status = 'adjusted' AND actual_starts_at IS NOT NULL AND actual_ends_at IS NOT NULL AND actual_ends_at > actual_starts_at) OR (attendance_status <> 'adjusted' AND actual_starts_at IS NULL AND actual_ends_at IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT IF EXISTS shifts_actual_times_valid');
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT IF EXISTS shifts_attendance_status_valid');

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attendance_recorded_by');
            $table->dropColumn([
                'attendance_status',
                'actual_starts_at',
                'actual_ends_at',
                'attendance_recorded_at',
            ]);
        });
    }
};
