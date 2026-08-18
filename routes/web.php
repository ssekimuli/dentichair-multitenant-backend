<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\PatientFormController;
use App\Http\Controllers\Web\AppointmentFormController;

Route::get('/', function () {
    return null;
});

// Patient Form Routes
Route::get('/patients/create', [PatientFormController::class, 'create'])->name('patient.create');
Route::post('/patients', [PatientFormController::class, 'store'])->name('patient.store');

// Appointment Form Routes
Route::get('/appointments/create', [AppointmentFormController::class, 'create'])->name('appointment.create');
Route::post('/appointments', [AppointmentFormController::class, 'store'])->name('appointment.store');
