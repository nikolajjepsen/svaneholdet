<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->string('name');
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX invitations_one_unresolved_per_recipient
            ON invitations (team_id, email)
            WHERE redeemed_at IS NULL AND withdrawn_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
