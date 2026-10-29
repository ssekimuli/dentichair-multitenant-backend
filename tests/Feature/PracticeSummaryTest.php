<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = RolePermission::create([
            'id' => 1,
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $this->branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $this->employee = Employee::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id
        ]);
    }

    /** @test */
    public function it_can_retrieve_practice_summary()
    {
        $today = Carbon::today();

        // 1. Active Patient
        $patient1 = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-15',
            'gender' => 'Male',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
            'last_visit' => $today->copy()->subDays(5),
            'created_at' => $today->copy()->subDays(10),
        ]);

        // 2. Inactive Patient registered recently (tests query builder clone bug)
        $patient2 = Patient::create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'date_of_birth' => '1995-05-20',
            'gender' => 'Female',
            'branch_id' => $this->branch->id,
            'status' => 'Inactive',
            'last_visit' => null,
            'created_at' => $today->copy()->subDays(2),
        ]);

        // 3. Paid Invoice with PaymentTransaction
        $invoice1 = Invoice::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient1->id,
            'amount' => 500.00,
            'paid_amount' => 300.00,
            'status' => 'Pending',
            'issue_date' => $today->toDateString(),
            'due_date' => $today->copy()->addDays(30)->toDateString(),
            'branch_id' => $this->branch->id,
        ]);

        PaymentTransaction::create([
            'invoice_id' => $invoice1->id,
            'amount' => 300.00,
            'method' => 'Cash',
            'date' => $today->toDateString(),
        ]);

        // 4. Cancelled Invoice (should not be included in revenue even with transaction)
        $invoice2 = Invoice::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient1->id,
            'amount' => 1000.00,
            'paid_amount' => 1000.00,
            'status' => 'Cancelled',
            'issue_date' => $today->toDateString(),
            'due_date' => $today->copy()->addDays(30)->toDateString(),
            'branch_id' => $this->branch->id,
        ]);

        PaymentTransaction::create([
            'invoice_id' => $invoice2->id,
            'amount' => 1000.00,
            'method' => 'Cash',
            'date' => $today->toDateString(),
        ]);

        // 5. Today's Scheduled Appointment
        Appointment::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient1->id,
            'doctor_name' => 'Dr. Gregory House',
            'doctor_id' => $this->employee->id,
            'treatment' => 'Root Canal',
            'status' => 'Scheduled',
            'date' => $today->toDateString(),
            'time' => '10:00 AM',
            'branch_id' => $this->branch->id,
            'token_number' => 'T101',
        ]);

        // 6. Past appointment within 30 days
        Appointment::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient1->id,
            'doctor_name' => 'Dr. Gregory House',
            'doctor_id' => $this->employee->id,
            'treatment' => 'Consultation',
            'status' => 'Completed',
            'date' => $today->copy()->subDays(15)->toDateString(),
            'time' => '11:00 AM',
            'branch_id' => $this->branch->id,
            'token_number' => 'T102',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/dashboard-summary?branch_id=' . $this->branch->id);

        $response->assertStatus(200);

        $data = $response->json();

        // Total revenue excludes cancelled invoice (300.00, not 1300.00)
        $this->assertEquals('300.00', $data['total_revenue']);
        // Active patients is 1 (only patient1)
        $this->assertEquals(1, $data['active_patients']);
        // New patients in last 30 days is 2 (both patient1 and patient2, even though patient2 is inactive)
        $this->assertEquals(2, $data['new_patients_last_30_days']);
        // Appointments in last 30 days is 2
        $this->assertEquals(2, $data['appointments_last_30_days']);

        // Upcoming appointments
        $this->assertCount(1, $data['upcoming_appointments']);
        $this->assertEquals('John Doe', $data['upcoming_appointments'][0]['patient_name']);
        $this->assertEquals('10:00 AM', $data['upcoming_appointments'][0]['time']);

        // Recent patients
        $this->assertNotEmpty($data['recent_patients']);
        $this->assertEquals($patient1->id, $data['recent_patients'][0]['id']);
    }

    /** @test */
    public function it_falls_back_to_relations_when_appointment_names_are_empty()
    {
        $today = Carbon::today();

        $patient = Patient::create([
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
            'email' => 'alice@example.com',
            'date_of_birth' => '1992-03-10',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        Appointment::create([
            'patient_name' => '',
            'patient_id' => $patient->id,
            'doctor_name' => '',
            'doctor_id' => $this->employee->id,
            'treatment' => 'Cleaning',
            'status' => 'Scheduled',
            'date' => $today->toDateString(),
            'time' => '09:00 AM',
            'branch_id' => $this->branch->id,
            'token_number' => 'T999',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/dashboard-summary?branch_id=' . $this->branch->id);

        $response->assertStatus(200);

        $upcoming = $response->json('upcoming_appointments');
        $this->assertCount(1, $upcoming);
        $this->assertEquals('Alice Wonderland', $upcoming[0]['patient_name']);
        $this->assertEquals($this->employee->name, $upcoming[0]['doctor_name']);
    }
}

