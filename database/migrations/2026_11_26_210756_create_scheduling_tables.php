<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('team_id');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignId('current_season_id')
                ->nullable()
                ->constrained('seasons')
                ->nullOnDelete();
        });

        Schema::create('slot_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('local_start_time', 5);
            $table->string('local_end_time', 5);
            $table->unsignedInteger('capacity');
            $table->timestamps();

            $table->index('season_id');
        });

        DB::statement('ALTER TABLE slot_series ADD CONSTRAINT slot_series_capacity_at_least_one CHECK (capacity >= 1)');

        Schema::create('slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_series_id')->nullable()->constrained('slot_series')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity');
            $table->timestamps();

            $table->index(['season_id', 'starts_at']);
            $table->index('slot_series_id');
        });

        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_ends_at_after_starts_at CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_capacity_at_least_one CHECK (capacity >= 1)');

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['slot_id', 'status']);
            $table->index(['membership_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX shifts_unique_active_slot_membership ON shifts (slot_id, membership_id) WHERE status IN ('pending', 'confirmed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('slots');
        Schema::dropIfExists('slot_series');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_season_id');
        });

        Schema::dropIfExists('seasons');
    }
};
