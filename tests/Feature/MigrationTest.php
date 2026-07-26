<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Create standard entities
        $this->branch = Branch::create([
            'id' => 1,
            'name' => 'Main Clinic',
            'address' => '123 Main St'
        ]);

        RolePermission::create([
            'id' => 4,
            'name' => 'Dentist',
            'permissions' => []
        ]);
    }

    /** @test */
    public function it_can_migrate_employees_from_csv()
    {
        $csvContent = "Full Name,Email Address,Monthly Salary,Hire Date,Role Name,Role ID,Branch ID,Status\n";
        $csvContent .= "Dr. Allan Ssekimuli,allan.sseki.sample@example.com,5500000.00,2022-03-01,Dentist,4,1,Active\n";
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $response = $this->actingAs($employee)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'employees'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully imported data for employees',
                'count' => 1
            ]);

        $this->assertDatabaseHas('employees', [
            'email' => 'allan.sseki.sample@example.com',
            'name' => 'Dr. Allan Ssekimuli',
            'role' => 'Dentist',
            'role_id' => 4,
            'salary' => 5500000.00,
            'status' => 'Active'
        ]);
    }

    /** @test */
    public function it_can_migrate_patients_from_csv()
    {
        $csvContent = "First Name,Last Name,Email,Phone Number,Gender,Birth Date,Address,Status,Medical History\n";
        $csvContent .= "John,Doe,john.doe.sample@example.com,+256700111222,Male,1995-05-12,123 Kampala Rd,Active,Has grinding teeth history\n";
        $file = UploadedFile::fake()->createWithContent('patients.csv', $csvContent);

        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $response = $this->actingAs($employee)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'patients'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully imported data for patients',
                'count' => 1
            ]);

        $this->assertDatabaseHas('patients', [
            'email' => 'john.doe.sample@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+256700111222',
            'gender' => 'Male',
            'date_of_birth' => '1995-05-12',
            'address' => '123 Kampala Rd',
            'status' => 'Active',
            'history' => 'Has grinding teeth history'
        ]);
    }

    /** @test */
    public function it_can_migrate_appointments_from_csv()
    {
        // Setup a doctor and a patient first
        $doctor = Employee::factory()->create([
            'email' => 'dr.smith@clinic.com',
            'name' => 'Dr. Smith',
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id
        ]);

        $csvContent = "patient_email,patient_phone,doctor_email,appointment_date,reason,status\n";
        $csvContent .= "john.doe@example.com,555-1234,dr.smith@clinic.com,2023-02-10 10:00:00,General Checkup,Completed\n";
        $file = UploadedFile::fake()->createWithContent('appointments.csv', $csvContent);

        $response = $this->actingAs($doctor)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'appointments'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully imported data for appointments',
                'count' => 1
            ]);

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'treatment' => 'General Checkup',
            'status' => 'Completed',
            'date' => '2023-02-10',
            'time' => '10:00'
        ]);
    }

    /** @test */
    public function it_can_migrate_financial_from_csv()
    {
        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id
        ]);

        $csvContent = "invoice_number,patient_email,patient_phone,amount,date,status\n";
        $csvContent .= "INV-1001,john.doe@example.com,555-1234,150.00,2023-01-15,Paid\n";
        $file = UploadedFile::fake()->createWithContent('financial.csv', $csvContent);

        $response = $this->actingAs($employee)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'financial'
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('invoices', [
            'patient_id' => $patient->id,
            'amount' => 150.00,
            'paid_amount' => 150.00,
            'status' => 'Paid',
            'issue_date' => '2023-01-15'
        ]);
    }

    /** @test */
    public function it_can_migrate_clinical_from_csv()
    {
        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id
        ]);

        $csvContent = "Procedure Name,Cost Amount,Category,patient_email,status,date\n";
        $csvContent .= "Teeth Cleaning,150000.00,Preventive,john.doe@example.com,Completed,2023-02-15\n";
        $file = UploadedFile::fake()->createWithContent('clinical.csv', $csvContent);

        $response = $this->actingAs($employee)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'clinical'
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('treatment_plans', [
            'patient_id' => $patient->id,
            'procedure' => 'Teeth Cleaning',
            'status' => 'Completed',
            'cost' => 150000.00,
            'date' => '2023-02-15'
        ]);
    }

    /** @test */
    public function it_auto_creates_missing_patient_and_doctor_and_ignores_email_casing_when_migrating_appointments()
    {
        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $csvContent = "patient_email,patient_phone,doctor_email,appointment_date,reason,status\n";
        $csvContent .= "NEW.PATIENT@example.com,555-4321,NEW.DOCTOR@example.com,2023-02-10 10:00:00,General Checkup,Completed\n";
        $file = UploadedFile::fake()->createWithContent('appointments_new.csv', $csvContent);

        $response = $this->actingAs($employee)
            ->postJson('/api/migrate', [
                'file' => $file,
                'section' => 'appointments'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully imported data for appointments',
                'count' => 1
            ]);

        // Verify patient was auto-created with lowercase email
        $this->assertDatabaseHas('patients', [
            'email' => 'new.patient@example.com',
            'first_name' => 'Imported',
            'last_name' => 'Patient',
            'phone' => '555-4321'
        ]);

        // Verify doctor was auto-created with lowercase email
        $this->assertDatabaseHas('employees', [
            'email' => 'new.doctor@example.com',
            'name' => 'Imported Doctor',
            'role' => 'Dentist',
            'role_id' => 4
        ]);

        // Verify appointment was imported
        $patient = \App\Models\Patient::where('email', 'new.patient@example.com')->first();
        $doctor = \App\Models\Employee::where('email', 'new.doctor@example.com')->first();

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'treatment' => 'General Checkup',
            'status' => 'Completed',
            'date' => '2023-02-10',
            'time' => '10:00'
        ]);
    }
}

