<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('creates two teams with one active admin account and sends no mail', function () {
    Mail::fake();

    $this->artisan('team:create')
        ->expectsQuestion('Team name', 'Hvid opstilling')
        ->expectsQuestion('Admin name', 'First Admin')
        ->expectsQuestion('Admin email', 'ADMIN@example.com')
        ->expectsOutput('Login URL: '.route('login'))
        ->assertSuccessful();

    $this->artisan('team:create')
        ->expectsQuestion('Team name', 'Another team')
        ->expectsQuestion('Admin name', 'Different Name')
        ->expectsQuestion('Admin email', 'admin@example.com')
        ->expectsOutput('Login URL: '.route('login'))
        ->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $userId = DB::table('users')->where('email', 'admin@example.com')->where('name', 'First Admin')->value('id');
    expect($userId)->not->toBeNull();
    foreach (['Hvid opstilling', 'Another team'] as $name) {
        $team = DB::table('teams')->where('name', $name)->where('timezone', 'Europe/Copenhagen')->first();
        expect($team)->not->toBeNull();
        $seasonId = DB::table('seasons')->where('team_id', $team->id)->where('name', 'Smukfest 2027')->value('id');
        expect($seasonId)->not->toBeNull();
        expect($team->current_season_id)->toBe($seasonId);
        $this->assertDatabaseHas('memberships', [
            'user_id' => $userId,
            'team_id' => $team->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
    Mail::assertNothingOutgoing();
});
