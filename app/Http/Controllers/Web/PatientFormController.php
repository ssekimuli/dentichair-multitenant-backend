<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Branch;
use Illuminate\Http\Request;

class PatientFormController extends Controller
{
    public function create()
    {
        $branches = Branch::all();
        return view('forms.patient-form', compact('branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'avatar' => 'nullable|string',
            'email' => 'nullable|email|unique:patients,email',
            'phone' => 'required|string|max:20|starts_with:+',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'required|date',
            'last_visit' => 'nullable|date',
            'status' => 'nullable|in:Active,Inactive',
            'branch_id' => 'required|exists:branches,id',
            'history' => 'nullable|string',
            'occlusal_records' => 'nullable|json',
            'nationality' => 'nullable|string|max:255',
        ]);

        $patient = Patient::create($validated);

        return redirect()->route('patient.show', $patient->id)->with('success', 'Patient created successfully');
    }
}
