<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\BookAppointmentController;
use App\Http\Controllers\Web\PatientRegistrationController;

Route::get('/', function () {
    return null;
});

// Book Appointment Routes
Route::get('/book-appointment', [BookAppointmentController::class, 'create'])
    ->middleware('throttle:20,1')
    ->name('book.appointment');

Route::post('/book-appointment', [BookAppointmentController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('book.appointment.store');

Route::get('/appointment/{token}/confirmation', [BookAppointmentController::class, 'confirmation'])
    ->name('appointment.confirmation');

// Patient Registration Routes
Route::get('/self-register', [PatientRegistrationController::class, 'create'])
    ->middleware('throttle:20,1')
    ->name('patient.self-register');

Route::post('/self-register', [PatientRegistrationController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('patient.register.store');

Route::get('/registration/{patient}/confirmation', [PatientRegistrationController::class, 'confirmation'])
    ->name('patient.registration.confirmation');


// Medical History Printable Case Sheet
Route::get('/medical-histories/{id}/print', [\App\Http\Controllers\MedicalHistoryController::class, 'print'])
    ->name('medical-histories.print');

// Invoice Printable A4 & Receipt
Route::get('/invoices/{id}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])
    ->name('invoices.print');

