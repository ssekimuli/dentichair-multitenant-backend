<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Branch;
use App\Mail\AppointmentBookedNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BookAppointmentController extends Controller
{
    public function create(Request $request)
    {
        $doctorRoles = ['Dental Assistant', 'Receptionist', 'Hygienist', 'Dentist','Super Admin'];
        $doctors = Employee::whereIn('role', $doctorRoles)
            ->where('status', 'Active')
            ->get(['id', 'name', 'email', 'role']);
        $branches = Branch::all(['id', 'name']);

        // Get patient data if redirected from self-registration
        $patient = null;
        if ($request->has('patient_id')) {
            $patient = Patient::find($request->query('patient_id'));
        }

        return view('forms.book-appointment', compact('doctors', 'branches', 'patient'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // Patient fields
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'gender' => 'nullable|in:Male,Female,Other',
            'age' => 'required|integer|min:1|max:150',
            'nationality' => 'nullable|string|max:255',
            'branch_id' => 'required|exists:branches,id',

            // Appointment fields
            'doctor_id' => 'required|exists:employees,id',
            'treatment' => 'required|string|max:255',
            'appointment_date' => 'required|date|before:+1 year',
            'appointment_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    if ($value < '08:00' || $value > '18:00') {
                        $fail('The appointment time must be between 08:00 and 18:00.');
                    }
                },
            ],
        ]);

        // Optional fields may be omitted entirely; default them so the create below never reads a missing key
        $validated = array_merge([
            'email' => null,
            'address' => null,
            'gender' => null,
            'nationality' => null,
        ], $validated);

        // Calculate date_of_birth from age
        $dateOfBirth = Carbon::now()->subYears($validated['age'])->format('Y-m-d');

        // Check for double-booking
        $conflictingAppointment = Appointment::where('doctor_id', $validated['doctor_id'])
            ->where('date', $validated['appointment_date'])
            ->where('time', $validated['appointment_time'])
            ->where('status', '!=', 'Cancelled')
            ->exists();

        if ($conflictingAppointment) {
            return back()->withErrors(['appointment_time' => 'This doctor is already booked at this time. Please choose another time.'])->withInput();
        }

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
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'gender' => $validated['gender'],
                'date_of_birth' => $dateOfBirth,
                'age' => $validated['age'],
                'status' => 'Inactive',
                'registration_source' => 'web_booking',
                'branch_id' => $validated['branch_id'],
                'nationality' => $validated['nationality'],
            ]);
        }

        // Get doctor info for appointment
        $doctor = Employee::find($validated['doctor_id']);

        // Create appointment with secure confirmation token
        $appointment = Appointment::create([
            'patient_name' => $patient->first_name . ' ' . $patient->last_name,
            'patient_id' => $patient->id,
            'doctor_name' => $doctor->name,
            'doctor_id' => $validated['doctor_id'],
            'treatment' => $validated['treatment'],
            'date' => $validated['appointment_date'],
            'time' => $validated['appointment_time'],
            'branch_id' => $patient->branch_id,
            'status' => 'Scheduled',
            'token_number' => $this->generateTokenNumber(),
            'confirmation_token' => Str::random(32),
        ]);

        // Send email to doctor
        if ($doctor->email) {
            Mail::to($doctor->email)
                ->send(new AppointmentBookedNotification($appointment));
        }

        // Send SMS to doctor
        if ($doctor->phone) {
            $this->sendDoctorSMS($appointment, $doctor);
        }

        return redirect()->route('appointment.confirmation', $appointment->confirmation_token)
            ->with('success', 'Appointment booked successfully!');
    }

    public function confirmation($token)
    {
        $appointment = Appointment::with(['patient', 'doctor' => function ($query) {
            $query->select('id', 'name', 'email');
        }])->where('confirmation_token', $token)->firstOrFail();

        return view('forms.appointment-confirmation', compact('appointment'));
    }

    private function sendDoctorSMS($appointment, $doctor)
    {
        try {
            $appointmentDate = \Carbon\Carbon::parse($appointment->date)->format('M d, Y');
            $appointmentTime = \Carbon\Carbon::parse($appointment->time, 'UTC')->format('h:i A');
            $patientName = $appointment->patient->first_name . ' ' . $appointment->patient->last_name;

            $message = "New appointment: {$patientName} on {$appointmentDate} at {$appointmentTime}. Treatment: {$appointment->treatment}. Token: {$appointment->token_number}";

            NotificationService::sendSms($doctor->phone, $message);
        } catch (\Exception $e) {
            \Log::error('Failed to send SMS to doctor: ' . $e->getMessage());
        }
    }

    private function generateTokenNumber()
    {
        $appName = strtoupper(substr(config('app.name'), 0, 3));
        $date = now()->format('Ymd');
        $random = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        return "{$appName}{$date}{$random}";
    }
}
