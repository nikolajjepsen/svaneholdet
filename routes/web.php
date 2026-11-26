<?php

use App\Http\Controllers\LogoutController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('dashboard', 'dashboard')->middleware('auth')->name('dashboard');
Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

require __DIR__.'/settings.php';
