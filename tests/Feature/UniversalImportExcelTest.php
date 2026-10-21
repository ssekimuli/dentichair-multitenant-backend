<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Services\Import\SpreadsheetReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalImportExcelTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->branch = Branch::create([
            'name' => 'Excel Clinic',
            'address' => '456 Excel Blvd',
        ]);

        RolePermission::create([
            'id' => 1,
            'name' => 'Admin',
            'permissions' => ['manage_patients'],
        ]);

        $this->employee = Employee::create([
            'name' => 'Excel Admin',
            'email' => 'excel.admin@example.com',
            'role' => 'Admin',
            'role_id' => 1,
            'branch_id' => $this->branch->id,
            'salary' => 6000.00,
            'hire_date' => '2024-01-01',
            'status' => 'Active',
            'password' => bcrypt('password123'),
        ]);
    }

    /** @test */
    public function it_can_detect_various_delimiters_in_csv()
    {
        $reader = app(SpreadsheetReaderService::class);

        // Comma
        $tempComma = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempComma, "A,B,C\n1,2,3");
        $this->assertEquals(',', $reader->detectDelimiter($tempComma));
        unlink($tempComma);

        // Semicolon
        $tempSemi = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempSemi, "A;B;C\n1;2;3");
        $this->assertEquals(';', $reader->detectDelimiter($tempSemi));
        unlink($tempSemi);

        // Tab
        $tempTab = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempTab, "A\tB\tC\n1\t2\t3");
        $this->assertEquals("\t", $reader->detectDelimiter($tempTab));
        unlink($tempTab);
    }

    /** @test */
    public function it_processes_semicolon_delimited_csv_files_seamlessly()
    {
        $csvContent = "Procedure Name;Cost Amount\nDental Crown;350.00\nVeneer;500.00\n";
        $file = UploadedFile::fake()->createWithContent('treatments_semi.csv', $csvContent);

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
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseHas('treatments', ['procedure' => 'Dental Crown', 'cost' => 350.00]);
        $this->assertDatabaseHas('treatments', ['procedure' => 'Veneer', 'cost' => 500.00]);
    }
}
