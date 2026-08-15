<?php

use App\Http\Controllers\CID10Controller;
use App\Http\Controllers\HealthPlanController;
use App\Http\Controllers\MedicalSpecialtyController;
use Illuminate\Support\Facades\Route;

Route::get('/health-plans/search', [HealthPlanController::class, 'search'])->name('health-plans.search');
Route::get('/medical-specialties/search', [MedicalSpecialtyController::class, 'search'])->name('medical-specialties.search');
Route::get('/cid10/search', [CID10Controller::class, 'search'])->name('cid10.search');
