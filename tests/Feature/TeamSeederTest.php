<?php

use App\Models\Team;
use App\Models\User;
use Database\Seeders\TeamSeeder;
use Illuminate\Support\Facades\Mail;

it('seeds the configured admin and ten volunteers on Hvid opstilling without sending mail', function () {
    Mail::fake();
    config(['seed.admin_name' => 'Festival Admin', 'seed.admin_email' => 'ADMIN@example.com']);
    $otherTeam = Team::factory()->create();

    $this->seed(TeamSeeder::class);
    $this->seed(TeamSeeder::class);

    $team = Team::query()->where('name', 'Hvid opstilling')->sole();
    $admin = User::query()->where('email', 'admin@example.com')->sole();
    expect($admin->name)->toBe('Festival Admin');
    $this->assertDatabaseHas('memberships', [
        'user_id' => $admin->id,
        'team_id' => $team->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
    expect($team->memberships()->where('role', 'volunteer')->where('status', 'active')->count())->toBe(10);
    expect($otherTeam->memberships()->count())->toBe(0);
    Mail::assertNothingOutgoing();
});
