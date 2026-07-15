<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsOverviewTest extends TestCase
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
    public function it_can_retrieve_reports_overview()
    {
        // Create Patient
        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'Male',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        // 1. Create financial records
        Invoice::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient->id,
            'amount' => 500.00,
            'paid_amount' => 300.00,
            'status' => 'Pending',
            'issue_date' => '2026-07-08',
            'due_date' => '2026-08-08',
            'branch_id' => $this->branch->id,
        ]);

        // 2. Create appointments
        Appointment::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patient->id,
            'doctor_name' => 'Dr. Gregory House',
            'doctor_id' => $this->employee->id,
            'treatment' => 'Teeth Cleaning',
            'status' => 'Scheduled',
            'date' => '2026-07-08',
            'time' => '10:00 AM',
            'branch_id' => $this->branch->id,
            'token_number' => 'T123',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/reports/overview');

        $response->assertStatus(200);

        // Check structured reports response
        $response->assertJsonStructure([
            'categories' => [
                'financial' => ['title', 'description'],
                'appointments' => ['title', 'description'],
                'demographics' => ['title', 'description'],
            ],
            'financial_summary' => ['total_income', 'total_expenses', 'net_profit'],
            'appointment_summary' => ['total_appointments', 'status_distribution'],
            'patient_summary' => ['total_patients', 'status_distribution', 'gender_distribution', 'age_distribution'],
        ]);

        // Check correct value
        $response->assertJsonFragment([
            'total_income' => '300.00',
            'total_appointments' => 1,
            'total_patients' => 1,
        ]);
    }

    /** @test */
    public function it_filters_reports_overview_by_date_range()
    {
        // Patient 1 (in range)
        $patientInRange = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'Male',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);
        $patientInRange->created_at = '2026-07-08 10:00:00';
        $patientInRange->save();

        // Patient 2 (out of range)
        $patientOutOfRange = Patient::create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'date_of_birth' => '1995-05-20',
            'gender' => 'Female',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);
        $patientOutOfRange->created_at = '2026-06-01 10:00:00';
        $patientOutOfRange->save();

        // Invoice 1 (in range)
        Invoice::create([
            'patient_name' => 'John Doe',
            'patient_id' => $patientInRange->id,
            'amount' => 500.00,
            'paid_amount' => 300.00,
            'status' => 'Pending',
            'issue_date' => '2026-07-08',
            'due_date' => '2026-08-08',
            'branch_id' => $this->branch->id,
        ]);

        // Invoice 2 (out of range)
        Invoice::create([
            'patient_name' => 'Jane Smith',
            'patient_id' => $patientOutOfRange->id,
            'amount' => 200.00,
            'paid_amount' => 200.00,
            'status' => 'Paid',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-07-01',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/reports/overview?start_date=2026-07-01&end_date=2026-07-15');

        $response->assertStatus(200);

        // Check filtered values
        $response->assertJsonFragment([
            'total_income' => '300.00',
            'total_patients' => 1,
        ]);
    }
}
