<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Branch;
use Illuminate\Http\Request;

class BookAppointmentController extends Controller
{
    public function create()
    {
        $doctors = Employee::all(['id', 'name', 'email']);
        $branches = Branch::all(['id', 'name']);

        return view('forms.book-appointment', compact('doctors', 'branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Patient fields
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'avatar' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20|starts_with:+',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'required|date',
            'nationality' => 'nullable|string|max:255',
            'branch_id' => 'required|exists:branches,id',

            // Appointment fields
            'doctor_id' => 'required|exists:employees,id',
            'treatment' => 'required|string',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required|string',
            'token_number' => 'nullable|string',
        ]);

        // Check if patient exists by phone first, then by email
        $patient = null;
        if (!empty($validated['phone'])) {
            $patient = Patient::where('phone', $validated['phone'])->first();
        }

        if (!$patient && !empty($validated['email'])) {
            $patient = Patient::where('email', $validated['email'])->first();
        }

        // Create patient if doesn't exist
        if (!$patient) {
            $patient = Patient::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'avatar' => $validated['avatar'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'],
                'status' => 'Active',
                'branch_id' => $validated['branch_id'],
                'nationality' => $validated['nationality'],
            ]);
        }

        // Get doctor info for appointment
        $doctor = Employee::find($validated['doctor_id']);

        // Create appointment
        $appointment = Appointment::create([
            'patient_name' => $patient->first_name . ' ' . $patient->last_name,
            'patient_id' => $patient->id,
            'doctor_name' => $doctor->name,
            'doctor_id' => $validated['doctor_id'],
            'treatment' => $validated['treatment'],
            'date' => $validated['appointment_date'],
            'time' => $validated['appointment_time'],
            'branch_id' => $patient->branch_id,
            'token_number' => $validated['token_number'],
        ]);

        return redirect()->route('appointment.confirmation', $appointment->id)
            ->with('success', 'Appointment booked successfully!');
    }

    public function confirmation($appointmentId)
    {
        $appointment = Appointment::with(['patient', 'doctor' => function ($query) {
            $query->select('id', 'name', 'email');
        }])->find($appointmentId);

        if (!$appointment) {
            return redirect()->route('book.appointment')->with('error', 'Appointment not found');
        }

        return view('forms.appointment-confirmation', compact('appointment'));
    }
}
