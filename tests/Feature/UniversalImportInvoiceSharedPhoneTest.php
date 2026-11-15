<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportInvoiceSharedPhoneTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_links_an_invoice_to_the_named_relative_who_shares_the_phone()
    {
        Storage::fake('local');

        $branch = Branch::create(['name' => 'Main Dental Clinic', 'address' => 'Kampala Road']);

        RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients'],
        ]);

        $employee = Employee::create([
            'name' => 'Dr. Admin',
            'email' => 'admin@dentistchair.com',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);

        Patient::create([
            'first_name' => 'Brenda', 'last_name' => 'Nakato', 'phone' => '0700111555',
            'branch_id' => $branch->id, 'gender' => 'Female',
            'date_of_birth' => '1980-03-04', 'status' => 'Active',
        ]);
        $samiiro = Patient::create([
            'first_name' => 'Samiiro', 'last_name' => 'Dahir', 'phone' => '0700111555',
            'branch_id' => $branch->id, 'gender' => 'Female',
            'date_of_birth' => '2015-01-01', 'status' => 'Active',
        ]);

        $csv = "NAME,CONTACT,AMOUNT,DATE\n";
        $csv .= "\"SAMIIRO DAHIR\",\"0700111555\",\"50000\",\"11/09/2026\"\n";

        $file = UploadedFile::fake()->createWithContent('invoice_family.csv', $csv);

        $this->actingAs($employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'patient_name',
                    'CONTACT' => 'contact',
                    'AMOUNT' => 'amount',
                    'DATE' => 'issue_date',
                ],
            ])
            ->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);

        $this->assertEquals($samiiro->id, Invoice::firstOrFail()->patient_id);
    }
}
