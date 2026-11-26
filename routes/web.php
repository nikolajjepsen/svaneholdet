<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\LogoutController;
use App\Http\Middleware\EnsureCurrentTeam;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('login', 'pages.auth.login')->name('login');
Route::post('login', [LoginLinkController::class, 'request'])->name('login.request');
Route::get('login/{token}', [LoginLinkController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->name('login.confirm');
Route::post('login/{token}', [LoginLinkController::class, 'consume'])->where('token', '[a-f0-9]{64}')->name('login.consume');

Route::get('invitations/accept/{token}', [InvitationController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->name('invitations.confirm');
Route::post('invitations/accept/{token}', [InvitationController::class, 'redeem'])->where('token', '[a-f0-9]{64}')->name('invitations.redeem');

Route::middleware(['auth', EnsureCurrentTeam::class])->group(function (): void {
    Route::get('dashboard', fn (Request $request) => view('dashboard', [
        'team' => $request->attributes->get(Team::class),
    ]))->name('dashboard');

    Route::livewire('invitations/create', 'pages::invitations.create')->name('invitations.create');
});
Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

require __DIR__.'/settings.php';
