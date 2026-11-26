<?php

use App\Http\Controllers\CurrentTeamController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\LogoutController;
use App\Http\Middleware\EnsureCurrentTeam;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('login', 'pages.auth.login')->middleware('guest')->name('login');
Route::post('login', [LoginLinkController::class, 'request'])->name('login.request');
Route::get('login/{token}', [LoginLinkController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->name('login.confirm');
Route::post('login/{token}', [LoginLinkController::class, 'consume'])->where('token', '[a-f0-9]{64}')->name('login.consume');

Route::get('invitations/accept/{token}', [InvitationController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->name('invitations.confirm');
Route::post('invitations/accept/{token}', [InvitationController::class, 'redeem'])->where('token', '[a-f0-9]{64}')->name('invitations.redeem');

Route::middleware(['auth', EnsureCurrentTeam::class])->group(function (): void {
    Route::get('dashboard', fn (Request $request) => view('dashboard', [
        'team' => $request->attributes->get(Team::class),
    ]))->name('dashboard');

    Route::livewire('schedule', 'pages::schedule.index')->name('schedule.index');
    Route::livewire('schedule/slots/{slot}', 'pages::schedule.slot')->name('schedule.slots.show');
    Route::livewire('schedule/rollout', 'pages::schedule.rollout')->name('schedule.rollout');
    Route::livewire('my-shifts', 'pages::my-shifts.index')->name('my-shifts.index');
    Route::livewire('invitations/create', 'pages::invitations.create')->name('invitations.create');
    Route::livewire('members', 'pages::members.index')->name('members.index');
    Route::livewire('team/settings', 'pages::team.settings')->name('team.settings');
    Route::post('current-team', CurrentTeamController::class)->name('current-team.update');
});
Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

require __DIR__.'/settings.php';
