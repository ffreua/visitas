<?php

use App\Http\Controllers\PatientController;
use Illuminate\Support\Facades\Route;

Route::get('/patients/lookup', [PatientController::class, 'lookup'])->name('patients.lookup');
Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
Route::get('/patients/{patient}/history', [PatientController::class, 'history'])->name('patients.history');
