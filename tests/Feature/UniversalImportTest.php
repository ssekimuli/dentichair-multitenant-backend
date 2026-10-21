<?php

namespace Tests\Feature;

use App\Jobs\ProcessUniversalImportJob;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\RolePermission;
use App\Models\Treatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->branch = Branch::create([
            'name' => 'Main Dental Clinic',
            'address' => '123 Health Ave',
        ]);

        $role = RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients', 'manage_employees'],
        ]);

        $this->employee = Employee::create([
            'name' => 'Dr. Andrew Sseki',
            'email' => 'dr.sseki@example.com',
            'phone' => '+256700000000',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $this->branch->id,
            'salary' => 5000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    /** @test */
    public function it_lists_authorized_import_tables()
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/import/tables');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'table',
                        'label',
                        'supported_operations',
                        'default_unique_by',
                        'supported_unique_keys',
                        'available_columns_count',
                    ],
                ],
            ]);

        $tables = collect($response->json('data'))->pluck('table')->toArray();
        $this->assertContains('patients', $tables);
        $this->assertContains('treatments', $tables);
        $this->assertContains('medicines', $tables);

        // Security check: sensitive system tables must never be exposed
        $this->assertNotContains('users', $tables);
        $this->assertNotContains('migrations', $tables);
        $this->assertNotContains('personal_access_tokens', $tables);
        $this->assertNotContains('password_reset_tokens', $tables);
    }

    /** @test */
    public function it_returns_discovered_columns_for_authorized_table()
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/import/tables/patients/columns');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table' => 'patients',
                ],
            ]);

        $columns = collect($response->json('data.columns'))->keyBy('name');

        // Check patient columns metadata
        $this->assertTrue($columns->has('first_name'));
        $this->assertTrue($columns->has('email'));
        $this->assertTrue($columns->has('gender'));

        $this->assertEquals('string', $columns['first_name']['type']);
        $this->assertTrue($columns['first_name']['is_writable']);

        // Sensitive or system fields must be excluded
        $this->assertFalse($columns->has('id'));
        $this->assertFalse($columns->has('created_at'));
        $this->assertFalse($columns->has('updated_at'));
        $this->assertFalse($columns->has('password'));
    }

    /** @test */
    public function it_blocks_access_to_unauthorized_tables()
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/import/tables/users/columns');

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function it_previews_csv_file_with_exact_matches_and_suggestions()
    {
        $csv = "First Name,Surname,Email Address,Phone Number,Unknown Header\n";
        $csv .= "Sarah,Connor,sarah@example.com,+123456789,CustomData\n";
        $csv .= "John,Doe,john@example.com,+987654321,OtherData\n";

        $file = UploadedFile::fake()->createWithContent('patients_preview.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'patients',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table' => 'patients',
                    'total_rows' => 2,
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['import_id']);
        $this->assertEquals(['First Name', 'Surname', 'Email Address', 'Phone Number', 'Unknown Header'], $data['headers']);

        // Check proposed mappings
        $this->assertEquals('first_name', $data['mapping']['First Name']);
        $this->assertEquals('last_name', $data['mapping']['Surname']); // mapped via alias 'surname'
        $this->assertEquals('email', $data['mapping']['Email Address']); // mapped via alias
        $this->assertEquals('phone', $data['mapping']['Phone Number']);

        // Unmapped headers
        $this->assertContains('Unknown Header', $data['unmapped_headers']);

        // Sample rows count
        $this->assertCount(2, $data['sample_rows']);
        $this->assertEquals('Sarah', $data['sample_rows'][0]['First Name']);
    }

    /** @test */
    public function it_rejects_empty_csv_during_preview()
    {
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'patients',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function it_executes_insert_mode_and_enforces_tenant_isolation()
    {
        $csv = "First Name,Last Name,Email,Phone,Gender,DOB\n";
        $csv .= "Alice,Wonderland,alice@example.com,111222,Female,1995-04-12\n";
        $csv .= "Bob,Builder,bob@example.com,333444,Male,1988-11-23\n";

        $file = UploadedFile::fake()->createWithContent('patients_insert.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Last Name' => 'last_name',
                    'Email' => 'email',
                    'Phone' => 'phone',
                    'Gender' => 'gender',
                    'DOB' => 'date_of_birth',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table' => 'patients',
                    'status' => 'completed',
                    'total_rows' => 2,
                    'imported' => 2,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('patients', 2);

        $patient = Patient::where('email', 'alice@example.com')->first();
        $this->assertNotNull($patient);
        $this->assertEquals('Alice', $patient->first_name);
        // Tenant context isolation check: branch_id must be employee's branch_id
        $this->assertEquals($this->branch->id, $patient->branch_id);
    }

    /** @test */
    public function it_imports_different_tables_using_the_same_engine()
    {
        // Import treatments table
        $treatmentCsv = "Procedure Name,Cost Amount\n";
        $treatmentCsv .= "Root Canal Therapy,450.00\n";
        $treatmentCsv .= "Tooth Extraction,120.00\n";

        $file = UploadedFile::fake()->createWithContent('treatments.csv', $treatmentCsv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'treatments',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'Procedure Name' => 'procedure',
                    'Cost Amount' => 'cost',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table' => 'treatments',
                    'imported' => 2,
                ],
            ]);

        $this->assertDatabaseHas('treatments', ['procedure' => 'Root Canal Therapy', 'cost' => 450.00]);

        // Import medicines table using the same API
        $medicineCsv = "Medicine Name,Medicine Category,Medicine Description\n";
        $medicineCsv .= "Amoxicillin 500mg,Antibiotics,Standard antibiotic capsule\n";

        $medFile = UploadedFile::fake()->createWithContent('medicines.csv', $medicineCsv);

        $medResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $medFile,
                'table' => 'medicines',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'Medicine Name' => 'name',
                    'Medicine Category' => 'category',
                    'Medicine Description' => 'description',
                ],
            ]);

        $medResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'table' => 'medicines',
                    'imported' => 1,
                ],
            ]);

        $this->assertDatabaseHas('medicines', ['name' => 'Amoxicillin 500mg', 'category' => 'Antibiotics', 'branch_id' => $this->branch->id]);
    }

    /** @test */
    public function it_handles_skip_duplicates_mode()
    {
        Patient::create([
            'first_name' => 'Existing',
            'last_name' => 'Patient',
            'email' => 'duplicate@example.com',
            'phone' => '111111',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id,
        ]);

        $csv = "First Name,Email\n";
        $csv .= "Existing,duplicate@example.com\n";
        $csv .= "BrandNew,new@example.com\n";

        $file = UploadedFile::fake()->createWithContent('patients_skip.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'skip_duplicates',
                'update_key' => 'email',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Email' => 'email',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'skipped' => 1,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('patients', 2);
    }

    /** @test */
    public function it_handles_upsert_mode_updating_existing_and_inserting_new()
    {
        Patient::create([
            'first_name' => 'OldFirstName',
            'last_name' => 'LastName',
            'email' => 'upsert@example.com',
            'phone' => '111111',
            'date_of_birth' => '1990-01-01',
            'branch_id' => $this->branch->id,
        ]);

        $csv = "First Name,Last Name,Email,Phone\n";
        $csv .= "NewUpdatedName,LastName,upsert@example.com,999999\n";
        $csv .= "FreshPatient,Smith,fresh@example.com,555555\n";

        $file = UploadedFile::fake()->createWithContent('patients_upsert.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'upsert',
                'update_key' => 'email',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Last Name' => 'last_name',
                    'Email' => 'email',
                    'Phone' => 'phone',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'updated' => 1,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('patients', 2);

        $updated = Patient::where('email', 'upsert@example.com')->first();
        $this->assertEquals('NewUpdatedName', $updated->first_name);
        $this->assertEquals('999999', $updated->phone);
    }

    /** @test */
    public function it_records_row_level_validation_errors_in_partial_mode()
    {
        // Row 1 has valid email
        // Row 2 has invalid email format
        // Row 3 has invalid gender value
        $csv = "First Name,Email,Gender\n";
        $csv .= "Valid User,valid@example.com,Female\n";
        $csv .= "Invalid Email,not-an-email,Male\n";
        $csv .= "Invalid Gender,user3@example.com,UnknownAlien\n";

        $file = UploadedFile::fake()->createWithContent('invalid_rows.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'error_mode' => 'partial',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Email' => 'email',
                    'Gender' => 'gender',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 2,
                ],
            ]);

        $jobId = $response->json('data.job_id');

        // Check error reporting endpoint
        $errorsResponse = $this->actingAs($this->employee)
            ->getJson("/api/import/jobs/{$jobId}/errors");

        $errorsResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'row_number',
                            'source_column',
                            'destination_field',
                            'error_code',
                            'error_message',
                        ],
                    ],
                ],
            ]);

        $errors = $errorsResponse->json('data.data');
        $this->assertCount(2, $errors);

        $this->assertEquals(3, $errors[0]['row_number']);
        $this->assertEquals('email', $errors[0]['destination_field']);

        $this->assertEquals(4, $errors[1]['row_number']);
        $this->assertEquals('gender', $errors[1]['destination_field']);
    }

    /** @test */
    public function it_fails_entire_batch_in_atomic_error_mode_on_validation_failure()
    {
        $csv = "First Name,Email\n";
        $csv .= "Row1,valid@example.com\n";
        $csv .= "Row2,broken-email\n";

        $file = UploadedFile::fake()->createWithContent('atomic_fail.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'error_mode' => 'atomic',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Email' => 'email',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'failed',
                    'imported' => 0,
                    'failed' => 1,
                ],
            ]);

        // Zero rows committed in atomic mode!
        $this->assertDatabaseCount('patients', 0);
    }

    /** @test */
    public function it_protects_against_csv_formula_injection_in_error_downloads()
    {
        // Malicious CSV cell starting with =cmd|'/C calc'!A0 or @SUM
        $csv = "First Name,Email\n";
        $csv .= "=HYPERLINK(\"http://malicious.com\"),invalid-email\n";

        $file = UploadedFile::fake()->createWithContent('injection.csv', $csv);

        $execResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'async' => false,
                'mapping' => [
                    'First Name' => 'first_name',
                    'Email' => 'email',
                ],
            ]);

        $jobId = $execResponse->json('data.job_id');

        $downloadResponse = $this->actingAs($this->employee)
            ->get("/api/import/jobs/{$jobId}/errors/download");

        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $downloadResponse->streamedContent();

        // Formula injection defense: any cell beginning with = must be prepended with '
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    /** @test */
    public function it_dispatches_background_queue_job_when_async_requested()
    {
        Queue::fake();

        $csv = "Procedure Name,Cost\nScaling,100\n";
        $file = UploadedFile::fake()->createWithContent('async_import.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'treatments',
                'async' => true,
                'mapping' => [
                    'Procedure Name' => 'procedure',
                    'Cost' => 'cost',
                ],
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                ],
            ]);

        Queue::assertPushed(ProcessUniversalImportJob::class);
    }

    /** @test */
    public function it_returns_paginated_import_history_scoped_to_user()
    {
        ImportJob::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'table_name' => 'patients',
            'file_name' => 'history1.csv',
            'status' => 'completed',
            'total_rows' => 50,
            'imported_count' => 50,
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/import/history');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'table_name',
                            'file_name',
                            'status',
                            'total_rows',
                        ],
                    ],
                ],
            ]);

        $this->assertCount(1, $response->json('data.data'));
    }

    /** @test */
    public function it_maps_date_to_last_visit_for_patients_and_splits_full_name_cleanly()
    {
        $csv = "DATE,NAME,CONTACT,PROCEDURE\n";
        $csv .= "01/06/2025,Ludigo Stephane,755734498,ortho\n";
        $csv .= "08/01/2025,Lunyoro Leticia,772799456,Extraction of 26\n";
        $csv .= "04/01/2025,Namugenyi Dalfa,,ortho\n";
        $csv .= "07/02/2025,Mukhaye Jane,703175753,Extraction of retained root\n";

        $file = UploadedFile::fake()->createWithContent('patients_visit_sheet.csv', $csv);

        // 1. Preview
        $previewResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'patients',
            ]);

        $previewResponse->assertStatus(200);
        $mapping = $previewResponse->json('data.mapping');

        $this->assertEquals('last_visit', $mapping['DATE']);
        $this->assertEquals('first_name', $mapping['NAME']);
        $this->assertEquals('phone', $mapping['CONTACT']);
        $this->assertEquals('history', $mapping['PROCEDURE']);
        $this->assertEmpty($previewResponse->json('data.unmapped_headers'));
        $this->assertEmpty($previewResponse->json('data.missing_required_fields'));

        // 2. Execute
        $executeResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'import_id' => $previewResponse->json('data.import_id'),
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => $mapping,
            ]);

        $executeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'imported' => 4,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('patients', 4);

        $patient1 = Patient::where('first_name', 'Ludigo')->first();
        $this->assertNotNull($patient1);
        $this->assertEquals('Stephane', $patient1->last_name);
        $this->assertEquals('755734498', $patient1->phone);
        $this->assertEquals('2025-06-01', $patient1->last_visit->format('Y-m-d'));
        $this->assertEquals('ortho', $patient1->history);
        $this->assertEquals('1999-01-01', $patient1->date_of_birth->format('Y-m-d'));
        $this->assertEquals($this->branch->id, $patient1->branch_id);

        $patient2 = Patient::where('first_name', 'Lunyoro')->first();
        $this->assertEquals('Leticia', $patient2->last_name);
        $this->assertEquals('2025-01-08', $patient2->last_visit->format('Y-m-d'));

        $patient3 = Patient::where('first_name', 'Namugenyi')->first();
        $this->assertEquals('Dalfa', $patient3->last_name);
        $this->assertNull($patient3->phone);
        $this->assertEquals('2025-01-04', $patient3->last_visit->format('Y-m-d'));
    }

    /** @test */
    public function it_checks_contact_and_updates_patient_if_already_exists()
    {
        // 1. Pre-existing patient with existing phone and demographic details
        $existingPatient = Patient::create([
            'first_name' => 'Sarah',
            'last_name' => 'Kintu',
            'phone' => '+256701234567',
            'email' => 'sarah.kintu@example.com',
            'gender' => 'Female',
            'date_of_birth' => '1992-04-18',
            'last_visit' => '2024-01-01',
            'history' => 'Previous root canal',
            'branch_id' => $this->branch->id,
            'status' => 'Active',
        ]);

        $this->assertDatabaseCount('patients', 1);

        // 2. Incoming spreadsheet data:
        // Row 1: Sarah with local phone format '0701234567' (matching existing '+256701234567'), new visit date and procedure
        // Row 2: Brand new patient 'David Otim'
        $csv = "NAME,CONTACT,DATE,PROCEDURE\n";
        $csv .= "Sarah Kintu,0701234567,2026-10-21,Crown replacement\n";
        $csv .= "David Otim,0788111222,2026-10-21,General Consultation\n";

        $file = UploadedFile::fake()->createWithContent('patients_update_check.csv', $csv);

        // Preview to test automatic alias mapping
        $previewResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'patients',
            ]);

        $previewResponse->assertStatus(200);
        $mapping = $previewResponse->json('data.mapping');

        $this->assertEquals('first_name', $mapping['NAME']);
        $this->assertEquals('phone', $mapping['CONTACT']);
        $this->assertEquals('last_visit', $mapping['DATE']);
        $this->assertEquals('history', $mapping['PROCEDURE']);

        // Execute import with default 'insert' mode
        $executeResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'import_id' => $previewResponse->json('data.import_id'),
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => $mapping,
            ]);

        $executeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'imported' => 1,
                    'updated' => 1,
                    'failed' => 0,
                ],
            ]);

        // Total patients count should now be 2 (1 updated + 1 inserted)
        $this->assertDatabaseCount('patients', 2);

        // Verify Sarah was updated with new visit & procedure, and existing demographics were preserved
        $updatedSarah = Patient::find($existingPatient->id);
        $this->assertNotNull($updatedSarah);
        $this->assertEquals($existingPatient->id, $updatedSarah->id);
        $this->assertEquals('Sarah', $updatedSarah->first_name);
        $this->assertEquals('Kintu', $updatedSarah->last_name);
        $this->assertEquals('sarah.kintu@example.com', $updatedSarah->email);
        $this->assertEquals('Female', $updatedSarah->gender);
        $this->assertEquals('1992-04-18', $updatedSarah->date_of_birth->format('Y-m-d'));
        $this->assertEquals('2026-10-21', $updatedSarah->last_visit->format('Y-m-d'));
        $this->assertEquals('Crown replacement', $updatedSarah->history);

        // Verify David was created as a new patient
        $david = Patient::where('first_name', 'David')->first();
        $this->assertNotNull($david);
        $this->assertEquals('Otim', $david->last_name);
        $this->assertEquals('0788111222', $david->phone);
        $this->assertEquals('2026-10-21', $david->last_visit->format('Y-m-d'));
        $this->assertEquals('General Consultation', $david->history);
    }
}

