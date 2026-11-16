<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportInvoiceDatesTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $branch = Branch::create([
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
            'branch_id' => $branch->id,
            'salary' => 8000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    /** @test */
    public function it_accepts_ordinal_day_invoice_dates_from_clinic_sheets()
    {
        $rows = [
            ['1st/11/2026', '2026-11-01'],
            ['2nd/11/2026', '2026-11-02'],
            ['3rd 11 2026', '2026-11-03'],
            ['23th/01/2026', '2026-01-23'],
            ['21st/11/2026', '2026-11-21'],
        ];

        $csv = "AMOUNT,DATE\n";
        foreach ($rows as $i => [$date]) {
            $csv .= (200 + $i) . ',"' . $date . "\"\n";
        }

        $file = UploadedFile::fake()->createWithContent('ordinal_dates.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'AMOUNT' => 'amount',
                    'DATE' => 'issue_date',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => count($rows), 'failed' => 0]]);

        $dates = Invoice::orderBy('amount')->pluck('issue_date')->map(fn ($d) => substr((string)$d, 0, 10))->all();
        $this->assertEquals(array_column($rows, 1), $dates);
    }

    /** @test */
    public function it_converts_mixed_invoice_date_formats_to_year_month_day()
    {
        $rows = [
            '1995-05-12',
            '12/05/1995',
            '12-05-1995',
            '12.05.1995',
            '12 May 1995',
            '12-May-1995',
            'May 12, 1995',
            '12/05/95',
            '1995/05/12',
            '12/05/1995 14:30',
        ];

        $csv = "AMOUNT,DATE\n";
        foreach ($rows as $i => $date) {
            $csv .= (100 + $i) . ',"' . $date . "\"\n";
        }

        $file = UploadedFile::fake()->createWithContent('invoice_dates.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'invoices',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'AMOUNT' => 'amount',
                    'DATE' => 'issue_date',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => count($rows), 'failed' => 0]]);

        $dates = Invoice::orderBy('amount')->pluck('issue_date')->map(fn ($d) => substr((string)$d, 0, 10))->all();

        $this->assertCount(count($rows), $dates);
        foreach ($dates as $date) {
            $this->assertEquals('1995-05-12', $date);
        }
    }
}
