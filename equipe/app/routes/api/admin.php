<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HealthPlanController;
use App\Http\Controllers\MedicalSpecialtyController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

    Route::get('/health-plans', [HealthPlanController::class, 'index'])->name('health-plans.index');
    Route::post('/health-plans', [HealthPlanController::class, 'store'])->name('health-plans.store');
    Route::put('/health-plans/{healthPlan}', [HealthPlanController::class, 'update'])->name('health-plans.update');

    Route::get('/medical-specialties', [MedicalSpecialtyController::class, 'index'])->name('medical-specialties.index');
    Route::post('/medical-specialties', [MedicalSpecialtyController::class, 'store'])->name('medical-specialties.store');
    Route::put('/medical-specialties/{medicalSpecialty}', [MedicalSpecialtyController::class, 'update'])->name('medical-specialties.update');

    Route::get('/system/integrity-check', [SystemController::class, 'integrityCheck'])->name('system.integrity-check');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/dashboard/data-quality', [DashboardController::class, 'dataQuality'])->name('dashboard.data-quality');
});
