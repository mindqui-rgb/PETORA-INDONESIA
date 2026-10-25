<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/health', HealthCheckController::class)->name('health');

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/masuk', [AuthenticatedSessionController::class, 'create'])->name('masuk');
    Route::post('/masuk', [AuthenticatedSessionController::class, 'store'])->name('masuk.store');
    Route::get('/daftar', [RegisteredUserController::class, 'create'])->name('daftar');
    Route::post('/daftar', [RegisteredUserController::class, 'store'])->name('daftar.store');
    Route::get('/lupa-kata-sandi', [PasswordResetLinkController::class, 'create'])->name('lupa-kata-sandi');
    Route::post('/lupa-kata-sandi', [PasswordResetLinkController::class, 'store'])->name('lupa-kata-sandi.store');
    Route::get('/reset-kata-sandi/{token}', [NewPasswordController::class, 'create'])->name('reset-kata-sandi');
    Route::post('/reset-kata-sandi', [NewPasswordController::class, 'store'])->name('reset-kata-sandi.update');
});

Route::middleware(['auth', 'verified', 'customer'])->group(function (): void {
    Route::get('/akun', [CustomerPortalController::class, 'index'])->name('akun');
});
