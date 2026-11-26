<?php

use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\LogoutController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('login', 'pages.auth.login')->name('login');
Route::post('login', [LoginLinkController::class, 'request'])->name('login.request');
Route::get('login/{token}', [LoginLinkController::class, 'confirm'])->where('token', '[a-f0-9]{64}')->name('login.confirm');
Route::post('login/{token}', [LoginLinkController::class, 'consume'])->where('token', '[a-f0-9]{64}')->name('login.consume');

Route::view('dashboard', 'dashboard')->middleware('auth')->name('dashboard');
Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

require __DIR__.'/settings.php';
