<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('UPDATE users SET email = lower(email) WHERE email <> lower(email)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase CHECK (email = lower(email))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_lowercase');
    }
};
