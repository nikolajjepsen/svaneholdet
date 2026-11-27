<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_hour_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('hours');
            $table->timestamps();

            $table->unique(['season_id', 'membership_id']);
            $table->index('membership_id');
        });

        DB::statement('ALTER TABLE season_hour_goals ADD CONSTRAINT season_hour_goals_nonnegative_hours CHECK (hours >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('season_hour_goals');
    }
};
