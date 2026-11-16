<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PatientRegistrationController extends Controller
{
    public function create()
    {
        $branches = Branch::all(['id', 'name']);
        return view('forms.patient-registration', compact('branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:patients,email',
            'phone' => 'nullable|string|max:20|unique:patients,phone',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'age' => 'required|integer|min:1|max:150',
            'nationality' => 'nullable|string|max:255',
            'branch_id' => 'required|exists:branches,id',
        ]);

        // Optional contact/demographic fields may be omitted entirely; default them so later reads are safe
        $validated = array_merge([
            'email' => null,
            'phone' => null,
            'address' => null,
            'gender' => null,
            'nationality' => null,
        ], $validated);

        // Calculate date_of_birth from age
        $dateOfBirth = Carbon::now()->subYears($validated['age'])->format('Y-m-d');

        // Check if patient already exists by phone or email.
        // Only match on the fields actually provided: a null phone must never match other patients without one.
        if (!empty($validated['phone']) || !empty($validated['email'])) {
            $existingPatient = Patient::where(function ($query) use ($validated) {
                    if (!empty($validated['phone'])) {
                        $query->orWhere('phone', $validated['phone']);
                    }
                    if (!empty($validated['email'])) {
                        $query->orWhere('email', $validated['email']);
                    }
                })
                ->first();

            if ($existingPatient) {
                return back()
                    ->withErrors(['phone' => 'A patient with this phone number or email already exists.'])
                    ->withInput();
            }
        }

        // Create patient
        $patient = Patient::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'address' => $validated['address'],
            'gender' => $validated['gender'],
            'date_of_birth' => $dateOfBirth,
            'age' => $validated['age'],
            'nationality' => $validated['nationality'],
            'branch_id' => $validated['branch_id'],
            'status' => 'Inactive',
            'registration_source' => 'web_self_register',
        ]);

        return redirect()->route('patient.registration.confirmation', $patient->id)
            ->with('success', 'Registration completed successfully!');
    }

    public function confirmation($patientId)
    {
        $patient = Patient::with('branch')->findOrFail($patientId);

        return view('forms.patient-registration-confirmation', compact('patient'));
    }
}
