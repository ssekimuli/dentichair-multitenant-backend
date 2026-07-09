<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        // Create standard entities
        $this->branch = Branch::create([
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
    public function it_can_parse_headers_from_uploaded_csv()
    {
        $csvContent = "First Name,Last Name,Email Address,Phone Number\nJohn,Doe,john@example.com,123456";
        $file = UploadedFile::fake()->createWithContent('patients.csv', $csvContent);

        // Mock authentication
        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $response = $this->actingAs($employee)
            ->postJson('/api/import/parse-headers', [
                'file' => $file,
                'type' => 'patients'
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['file_path', 'headers', 'available_columns']);

        $data = $response->json();
        $this->assertEquals(['First Name', 'Last Name', 'Email Address', 'Phone Number'], $data['headers']);
        $this->assertContains('email', $data['available_columns']);
    }

    /** @test */
    public function it_starts_background_import_job_for_patients()
    {
        // 1. Create a dummy file in storage
        $filePath = 'imports/test_patients.csv';
        $csvContent = "First Name,Last Name,Email Address,Phone Number\nJohn,Doe,john@example.com,123456";
        Storage::disk('local')->put($filePath, $csvContent);

        // Mock authentication
        $employee = Employee::factory()->create([
            'role_id' => 4,
            'branch_id' => $this->branch->id
        ]);

        $response = $this->actingAs($employee)
            ->postJson('/api/import/start', [
                'file_path' => $filePath,
                'type' => 'patients',
                'mapping' => [
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'email' => 'Email Address',
                    'phone' => 'Phone Number'
                ]
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['import_id', 'status', 'message']);

        $importId = $response->json('import_id');
        $this->assertNotNull($importId);

        // Check if job is dispatched
        Queue::assertDispatched(\App\Jobs\ProcessImportJob::class);

        // Check initial progress status in cache
        $progress = Cache::get('import_progress_' . $importId);
        $this->assertEquals('queued', $progress['status']);
    }

    /** @test */
    public function it_imports_and_upserts_patients_during_job_handling()
    {
        // Create an existing patient to verify upsert/update
        Patient::create([
            'first_name' => 'Original',
            'last_name' => 'Name',
            'email' => 'existing@example.com',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id
        ]);

        // Create CSV file containing 1 new patient and 1 update for existing patient
        $csvContent = "First Name,Last Name,Email Address,Phone,Gender,DOB\n";
        $csvContent .= "John,Doe,john@example.com,123456,Male,1995-05-10\n";
        $csvContent .= "Updated,Name,existing@example.com,777777,Female,1990-01-01\n";

        $tempPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempPath, $csvContent);

        $job = new \App\Jobs\ProcessImportJob(
            'test-import-id',
            $tempPath,
            'patients',
            [
                'first_name' => 'First Name',
                'last_name' => 'Last Name',
                'email' => 'Email Address',
                'phone' => 'Phone',
                'gender' => 'Gender',
                'date_of_birth' => 'DOB'
            ]
        );

        $job->handle();

        // 1. Should have exactly 2 patients in DB now (1 existing updated, 1 new inserted)
        $this->assertDatabaseCount('patients', 2);

        // 2. Verify new patient
        $newPatient = Patient::where('email', 'john@example.com')->first();
        $this->assertNotNull($newPatient);
        $this->assertEquals('John', $newPatient->first_name);
        $this->assertEquals('123456', $newPatient->phone);

        // 3. Verify updated patient
        $updatedPatient = Patient::where('email', 'existing@example.com')->first();
        $this->assertEquals('Updated', $updatedPatient->first_name);
        $this->assertEquals('777777', $updatedPatient->phone);
        $this->assertEquals('Female', $updatedPatient->gender);

        // 4. Verify progress is completed in Cache
        $progress = Cache::get('import_progress_test-import-id');
        $this->assertEquals('completed', $progress['status']);
        $this->assertEquals(2, $progress['processed_rows']);
    }

    /** @test */
    public function it_imports_and_upserts_employees_during_job_handling()
    {
        $csvContent = "Full Name,Email Address,Salary,Hire Date,Role ID\n";
        $csvContent .= "New Employee,new.emp@example.com,120000,2026-01-15,4\n";

        $tempPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempPath, $csvContent);

        $job = new \App\Jobs\ProcessImportJob(
            'test-import-employee',
            $tempPath,
            'employees',
            [
                'name' => 'Full Name',
                'email' => 'Email Address',
                'salary' => 'Salary',
                'hire_date' => 'Hire Date',
                'role_id' => 'Role ID'
            ]
        );

        $job->handle();

        $this->assertDatabaseCount('employees', 1);

        $employee = Employee::where('email', 'new.emp@example.com')->first();
        $this->assertNotNull($employee);
        $this->assertEquals('New Employee', $employee->name);
        $this->assertEquals('Dentist', $employee->role);
        $this->assertEquals(4, $employee->role_id);
        $this->assertEquals(120000.00, $employee->salary);
    }

    /** @test */
    public function it_imports_and_upserts_treatments_during_job_handling()
    {
        $csvContent = "Procedure Name,Cost Amount\n";
        $csvContent .= "Dental Cleaning,150.00\n";

        $tempPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempPath, $csvContent);

        $job = new \App\Jobs\ProcessImportJob(
            'test-import-treatment',
            $tempPath,
            'treatments',
            [
                'procedure' => 'Procedure Name',
                'cost' => 'Cost Amount'
            ]
        );

        $job->handle();

        $this->assertDatabaseCount('treatments', 1);

        $treatment = Treatment::where('procedure', 'Dental Cleaning')->first();
        $this->assertNotNull($treatment);
        $this->assertEquals(150.00, $treatment->cost);
    }
}
