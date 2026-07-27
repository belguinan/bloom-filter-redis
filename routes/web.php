<?php

use App\Http\Controllers\EmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EmailVerificationController::class, 'index'])->name('verification.index');
Route::post('/verify', [EmailVerificationController::class, 'verify'])->name('verification.verify');
