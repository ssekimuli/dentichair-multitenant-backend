<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportInvoicesTest extends TestCase
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
            'address' => 'Kampala Road',
        ]);

        RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients'],
        ]);

        $this->employee = Employee::create([
            'name' => 'Dr. Admin',
            'email' => 'admin@dentistchair.com',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $this->branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    /** @test */
    public function it_proposes_automatic_mapping_for_invoice_columns()
    {
        $csv = "NAME,CONTACT,AMOUNT,PAID,DUE DATE,DATE,TREATMENT\n";
        $csv .= "Andrew,+256700111222,150000,150000,2026-08-15,2026-08-15,Routine Dental Cleaning\n";

        $file = UploadedFile::fake()->createWithContent('invoices_test.csv', $csv);

        $previewResponse = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $file,
                'table' => 'invoices',
            ]);

        $previewResponse->assertStatus(200);
        $mapping = $previewResponse->json('data.mapping');

        $this->assertEquals('patient_name', $mapping['NAME']);
        $this->assertEquals('contact', $mapping['CONTACT']);
        $this->assertEquals('amount', $mapping['AMOUNT']);
        $this->assertEquals('paid_amount', $mapping['PAID']);
        $this->assertEquals('due_date', $mapping['DUE DATE']);
        $this->assertEquals('issue_date', $mapping['DATE']);
        $this->assertEquals('procedure', $mapping['TREATMENT']);

        $this->assertEmpty($previewResponse->json('data.unmapped_headers'));
        $this->assertEmpty($previewResponse->json('data.missing_required_fields'));
    }

    /** @test */
    public function it_links_invoice_to_patient_when_contact_matches()
    {
        // 1. Create registered patient with phone +256700111222
        $patient = Patient::create([
            'first_name' => 'Andrew',
            'last_name' => 'Mukasa',
            'phone' => '+256700111222',
            'branch_id' => $this->branch->id,
            'gender' => 'Male',
            'date_of_birth' => '1995-05-10',
            'status' => 'Active',
        ]);

        $this->assertDatabaseCount('patients', 1);

        // 2. Import invoice with matching contact
        $csv = "NAME,CONTACT,AMOUNT,PAID,DUE DATE,DATE,TREATMENT\n";
        $csv .= "Andrew,+256700111222,150000,150000,2026-08-15,2026-08-15,Routine Dental Cleaning\n";

        $file = UploadedFile::fake()->createWithContent('invoice_matched.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'CONTACT' => 'contact',
                    'AMOUNT' => 'amount',
                    'PAID' => 'paid_amount',
                    'DUE DATE' => 'due_date',
                    'DATE' => 'issue_date',
                    'TREATMENT' => 'procedure',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        // Invoice should be linked to the patient
        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $this->assertEquals($patient->id, $invoice->patient_id);
        $this->assertEquals('Andrew', $invoice->patient_name);
        $this->assertEquals(150000, $invoice->amount);
        $this->assertEquals(150000, $invoice->paid_amount);

        // Invoice treatment should be recorded
        $this->assertDatabaseCount('invoice_treatments', 1);
        $treatment = DB::table('invoice_treatments')->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($treatment);
        $this->assertEquals('Routine Dental Cleaning', $treatment->procedure);

        // NO new patient should have been created
        $this->assertDatabaseCount('patients', 1);
    }

    /** @test */
    public function it_imports_invoice_without_error_when_contact_does_not_match()
    {
        // 1. Create a registered patient with a DIFFERENT phone number
        $patient = Patient::create([
            'first_name' => 'Existing',
            'last_name' => 'Patient',
            'phone' => '+256777000111',
            'branch_id' => $this->branch->id,
            'gender' => 'Female',
            'date_of_birth' => '1998-03-12',
            'status' => 'Active',
        ]);

        $this->assertDatabaseCount('patients', 1);

        // 2. Import invoice with a contact that does NOT exist in patients table
        $csv = "NAME,CONTACT,AMOUNT,PAID,DUE DATE,DATE,TREATMENT\n";
        $csv .= "Unmatched Person,+256799888777,250000,100000,2026-09-01,2026-09-01,Tooth Extraction\n";

        $file = UploadedFile::fake()->createWithContent('invoice_unmatched.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'CONTACT' => 'contact',
                    'AMOUNT' => 'amount',
                    'PAID' => 'paid_amount',
                    'DUE DATE' => 'due_date',
                    'DATE' => 'issue_date',
                    'TREATMENT' => 'procedure',
                ],
            ]);

        // Must succeed without error
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        // Invoice is imported with patient_id as null
        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->patient_id);
        $this->assertEquals('Unmatched Person', $invoice->patient_name);
        $this->assertEquals(250000, $invoice->amount);
        $this->assertEquals(100000, $invoice->paid_amount);

        // NO new patient should have been auto-created!
        $this->assertDatabaseCount('patients', 1);
    }

    /** @test */
    public function it_imports_invoice_without_error_when_contact_is_empty()
    {
        $csv = "NAME,CONTACT,AMOUNT,PAID,DUE DATE,DATE,TREATMENT\n";
        $csv .= "alisha,,,,,,,\n";

        $file = UploadedFile::fake()->createWithContent('invoice_empty_contact.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'CONTACT' => 'contact',
                    'AMOUNT' => 'amount',
                    'PAID' => 'paid_amount',
                    'DUE DATE' => 'due_date',
                    'DATE' => 'issue_date',
                    'TREATMENT' => 'procedure',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->patient_id);
        $this->assertEquals('alisha', $invoice->patient_name);
        $this->assertEquals(0, $invoice->amount);

        // NO patient created
        $this->assertDatabaseCount('patients', 0);
    }

    /** @test */
    public function it_processes_user_sample_excel_file_correctly()
    {
        $sampleExcelPath = 'C:/Users/sseki/Downloads/invoices_sample (2).xlsx';
        if (!file_exists($sampleExcelPath)) {
            $this->markTestSkipped("Sample Excel file not found at {$sampleExcelPath}");
        }

        // Pre-create patients matching row 0 and row 1 in the sample file
        $andrew = Patient::create([
            'first_name' => 'Andrew',
            'last_name' => 'K',
            'phone' => '+256700111222',
            'branch_id' => $this->branch->id,
            'gender' => 'Male',
            'date_of_birth' => '1992-01-01',
            'status' => 'Active',
        ]);

        $john = Patient::create([
            'first_name' => 'John',
            'last_name' => 'M',
            'phone' => '+256700333444',
            'branch_id' => $this->branch->id,
            'gender' => 'Male',
            'date_of_birth' => '1990-02-02',
            'status' => 'Active',
        ]);

        // ben and alisha are NOT pre-created in the database
        $this->assertDatabaseCount('patients', 2);

        $uploadedFile = new UploadedFile(
            $sampleExcelPath,
            'invoices_sample (2).xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        // 1. Preview
        $previewRes = $this->actingAs($this->employee)
            ->postJson('/api/import/preview', [
                'file' => $uploadedFile,
                'table' => 'invoices',
            ]);

        $previewRes->assertStatus(200);
        $mapping = $previewRes->json('data.mapping');

        $this->assertEquals('patient_name', $mapping['NAME']);
        $this->assertEquals('contact', $mapping['CONTACT']);
        $this->assertEquals('amount', $mapping['AMOUNT']);
        $this->assertEquals('paid_amount', $mapping['PAID']);
        $this->assertEquals('due_date', $mapping['DUE DATE']);
        $this->assertEquals('issue_date', $mapping['DATE']);
        $this->assertEquals('procedure', $mapping['TREATMENT']);

        // 2. Execute
        $execRes = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'import_id' => $previewRes->json('data.import_id'),
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => $mapping,
            ]);

        $execRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 4,
                    'failed' => 0,
                ],
            ]);

        // All 4 rows should be imported into invoices
        $this->assertDatabaseCount('invoices', 4);

        // Andrew should be linked to Andrew's patient_id and have status 'Paid' (no balance)
        $andrewInv = Invoice::where('patient_name', 'Andrew')->first();
        $this->assertNotNull($andrewInv);
        $this->assertEquals($andrew->id, $andrewInv->patient_id);
        $this->assertEquals(150000, $andrewInv->amount);
        $this->assertEquals(150000, $andrewInv->paid_amount);
        $this->assertEquals(0, $andrewInv->patient_balance);
        $this->assertEquals('Paid', $andrewInv->status);

        // John has 350,000 balance remaining -> status must be 'Pending'
        $johnInv = Invoice::where('patient_name', 'john')->first();
        $this->assertNotNull($johnInv);
        $this->assertEquals($john->id, $johnInv->patient_id);
        $this->assertEquals(850000, $johnInv->amount);
        $this->assertEquals(500000, $johnInv->paid_amount);
        $this->assertEquals(350000, $johnInv->patient_balance);
        $this->assertEquals('Pending', $johnInv->status);

        // Ben has 1,200,000 balance remaining -> status must be 'Pending'
        $benInv = Invoice::where('patient_name', 'ben')->first();
        $this->assertNotNull($benInv);
        $this->assertNull($benInv->patient_id);
        $this->assertEquals(1200000, $benInv->amount);
        $this->assertEquals(0, $benInv->paid_amount);
        $this->assertEquals(1200000, $benInv->patient_balance);
        $this->assertEquals('Pending', $benInv->status);

        // Alisha has no contact and 0 balance -> patient_id must be null, status 'Paid'
        $alishaInv = Invoice::where('patient_name', 'alisha')->first();
        $this->assertNotNull($alishaInv);
        $this->assertNull($alishaInv->patient_id);
        $this->assertEquals('Paid', $alishaInv->status);

        // Patients count should remain 2 (NO patients were auto-created!)
        $this->assertDatabaseCount('patients', 2);

        // Treatments should be recorded for the 3 rows with treatments
        $this->assertDatabaseCount('invoice_treatments', 3);
        $this->assertDatabaseHas('invoice_treatments', ['procedure' => 'Routine Dental Cleaning']);
        $this->assertDatabaseHas('invoice_treatments', ['procedure' => 'Root Canal Treatment']);
        $this->assertDatabaseHas('invoice_treatments', ['procedure' => 'Porcelain Fused to Metal Crown']);
    }

    /** @test */
    public function it_calculates_balance_and_sets_status_pending_when_balance_remains()
    {
        $csv = "NAME,AMOUNT,PAID\n";
        $csv .= "Patient One,500000,200000\n";
        $csv .= "Patient Two,300000,300000\n";

        $file = UploadedFile::fake()->createWithContent('balance_test.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'AMOUNT' => 'amount',
                    'PAID' => 'paid_amount',
                ],
            ]);

        $response->assertStatus(200);

        $inv1 = Invoice::where('patient_name', 'Patient One')->first();
        $this->assertNotNull($inv1);
        $this->assertEquals(300000, $inv1->patient_balance);
        $this->assertEquals('Pending', $inv1->status);

        $inv2 = Invoice::where('patient_name', 'Patient Two')->first();
        $this->assertNotNull($inv2);
        $this->assertEquals(0, $inv2->patient_balance);
        $this->assertEquals('Paid', $inv2->status);
    }
}
