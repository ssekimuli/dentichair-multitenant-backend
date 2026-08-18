<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\BookAppointmentController;

Route::get('/', function () {
    return null;
});

// Book Appointment Routes
Route::get('/book-appointment', [BookAppointmentController::class, 'create'])->name('book.appointment');
Route::post('/book-appointment', [BookAppointmentController::class, 'store'])->name('book.appointment.store');
Route::get('/appointment/{appointment}/confirmation', [BookAppointmentController::class, 'confirmation'])->name('appointment.confirmation');

