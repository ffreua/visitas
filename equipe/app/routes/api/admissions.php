<?php

use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AdmissionDiagnosisController;
use App\Http\Controllers\DailyRoundController;
use App\Http\Controllers\PendingItemController;
use Illuminate\Support\Facades\Route;

Route::get('/admissions', [AdmissionController::class, 'index'])->name('admissions.index');
Route::get('/admissions/closed', [AdmissionController::class, 'closed'])->name('admissions.closed');
Route::get('/admissions/trashed', [AdmissionController::class, 'trashed'])->name('admissions.trashed');
Route::post('/admissions', [AdmissionController::class, 'store'])->name('admissions.store');

Route::get('/admissions/{admission}', [AdmissionController::class, 'show'])->name('admissions.show');
Route::put('/admissions/{admission}', [AdmissionController::class, 'update'])->name('admissions.update');
Route::post('/admissions/{admission}/close', [AdmissionController::class, 'close'])->name('admissions.close');
Route::post('/admissions/{admission}/convert-to-followup', [AdmissionController::class, 'convertToFollowup'])->name('admissions.convert-to-followup');
Route::delete('/admissions/{admission}', [AdmissionController::class, 'destroy'])->name('admissions.destroy');

Route::post('/admissions/{admission}/diagnoses', [AdmissionDiagnosisController::class, 'store'])->name('admissions.diagnoses.store');
Route::post('/admissions/{admission}/pending-items', [PendingItemController::class, 'store'])->name('admissions.pending-items.store');
Route::post('/pending-items/{pendingItem}/resolve', [PendingItemController::class, 'resolve'])->name('pending-items.resolve');

Route::post('/admissions/{admission}/rounds/assign', [DailyRoundController::class, 'assign'])->name('admissions.rounds.assign');
Route::post('/admissions/{admission}/rounds/complete', [DailyRoundController::class, 'complete'])->name('admissions.rounds.complete');

// Excluídos (admin) — binding explícito incluindo soft-deleted.
Route::post('/admissions/{trashedAdmission}/restore', [AdmissionController::class, 'restore'])
    ->name('admissions.restore')->withTrashed();
Route::delete('/admissions/{trashedAdmission}/force', [AdmissionController::class, 'forceDestroy'])
    ->name('admissions.force-destroy')->withTrashed();
