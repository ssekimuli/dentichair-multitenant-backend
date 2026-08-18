<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Branch;
use App\Mail\AppointmentBookedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
            'status' => 'Scheduled',
            'token_number' => $this->generateTokenNumber(),
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

    private function sendDoctorSMS($appointment, $doctor)
    {
        try {
            $appointmentDate = \Carbon\Carbon::parse($appointment->date)->format('M d, Y');
            $appointmentTime = \Carbon\Carbon::parse($appointment->time, 'UTC')->format('h:i A');
            $patientName = $appointment->patient->first_name . ' ' . $appointment->patient->last_name;

            $message = "New appointment booking: {$patientName} on {$appointmentDate} at {$appointmentTime}. Treatment: {$appointment->treatment}. Token: {$appointment->token_number}";

            // Send SMS using the notification service
            $this->sendSMS($doctor->phone, $message);
        } catch (\Exception $e) {
            \Log::error('Failed to send SMS to doctor: ' . $e->getMessage());
        }
    }

    private function sendSMS($phone, $message)
    {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => env('SMS_API_URL', 'https://api.africastalking.com/version1/messaging'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query([
                    'username' => env('SMS_USERNAME'),
                    'message' => $message,
                    'recipients' => $phone,
                ]),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'apiKey: ' . env('SMS_API_KEY'),
                ],
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            return $response;
        } catch (\Exception $e) {
            \Log::error('SMS sending error: ' . $e->getMessage());
            return false;
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
