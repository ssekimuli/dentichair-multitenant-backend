<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Employee;
use App\Models\Branch;
use Illuminate\Http\Request;

class AppointmentFormController extends Controller
{
    public function create()
    {
        $patients = Patient::all(['id', 'first_name', 'last_name', 'email']);
        $doctors = Employee::all(['id', 'name', 'email']);
        $branches = Branch::all(['id', 'name']);

        return view('forms.appointment-form', compact('patients', 'doctors', 'branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'required|string',
            'patient_id' => 'required|exists:patients,id',
            'doctor_name' => 'required|string',
            'doctor_id' => 'required|exists:employees,id',
            'treatment' => 'required|string',
            'date' => 'required|date',
            'time' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'token_number' => 'nullable|string',
        ]);

        $appointment = Appointment::create($validated);

        return redirect()->route('appointment.show', $appointment->id)->with('success', 'Appointment created successfully');
    }
}
