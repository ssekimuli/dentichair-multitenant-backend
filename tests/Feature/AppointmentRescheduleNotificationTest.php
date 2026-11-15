<?php

namespace Tests\Feature;

use App\Http\Controllers\AppointmentController;
use App\Mail\GenericMarkdownMail;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AppointmentRescheduleNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Patient $patient;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main Clinic', 'address' => '123 Main St']);

        $this->patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '0700000000',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $doctor = Employee::factory()->create([
            'name' => 'Dr. Smith',
            'email' => 'dr.smith@example.com',
            'branch_id' => $this->branch->id,
        ]);

        $this->appointment = Appointment::create([
            'patient_name' => 'John Doe',
            'patient_id' => $this->patient->id,
            'doctor_name' => $doctor->name,
            'doctor_id' => $doctor->id,
            'treatment' => 'Check-up',
            'status' => 'Scheduled',
            'date' => '2026-12-01',
            'time' => '10:00 AM',
            'branch_id' => $this->branch->id,
            'token_number' => 'T123456',
        ]);

        Mail::fake();
    }

    private function update(array $data)
    {
        $request = Request::create('/api/appointments/' . $this->appointment->id, 'PUT', $data);
        return app(AppointmentController::class)->update($request, $this->appointment->id);
    }

    /** @test */
    public function changing_date_and_time_emails_patient_and_doctor()
    {
        $response = $this->update(['date' => '2026-12-05', 'time' => '02:30 PM']);

        $this->assertEquals(200, $response->getStatusCode());

        Mail::assertSent(GenericMarkdownMail::class, function ($mail) {
            return $mail->viewName === 'emails.appointment-rescheduled'
                && $mail->hasTo('john.doe@example.com')
                && $mail->viewData['oldDate'] === '2026-12-01'
                && $mail->viewData['oldTime'] === '10:00 AM'
                && $mail->viewData['newDate'] === '2026-12-05'
                && $mail->viewData['newTime'] === '02:30 PM'
                && $mail->viewData['forDoctor'] === false;
        });

        Mail::assertSent(GenericMarkdownMail::class, function ($mail) {
            return $mail->viewName === 'emails.appointment-rescheduled'
                && $mail->hasTo('dr.smith@example.com')
                && $mail->viewData['forDoctor'] === true;
        });
    }

    /** @test */
    public function changing_only_the_doctor_triggers_reschedule_notice()
    {
        $other = Employee::factory()->create([
            'name' => 'Dr. Jones',
            'email' => 'dr.jones@example.com',
            'branch_id' => $this->branch->id,
        ]);

        $this->update(['doctor_id' => $other->id, 'doctor_name' => $other->name]);

        Mail::assertSent(GenericMarkdownMail::class, function ($mail) {
            return $mail->viewName === 'emails.appointment-rescheduled'
                && $mail->hasTo('john.doe@example.com');
        });
    }

    /** @test */
    public function status_only_change_does_not_send_reschedule_notice()
    {
        $this->update(['status' => 'Waiting']);

        Mail::assertNotSent(GenericMarkdownMail::class, function ($mail) {
            return $mail->viewName === 'emails.appointment-rescheduled';
        });
        Mail::assertSent(GenericMarkdownMail::class, function ($mail) {
            return $mail->viewName === 'emails.appointment-status';
        });
    }

    /** @test */
    public function unchanged_slot_sends_nothing()
    {
        $this->update(['date' => '2026-12-01', 'time' => '10:00 AM']);

        Mail::assertNothingSent();
    }
}
