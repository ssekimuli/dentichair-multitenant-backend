<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\BookAppointmentController;

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

