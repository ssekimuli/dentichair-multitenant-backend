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

class UniversalImportPhoneCleanTest extends TestCase
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
    public function it_strips_symbols_from_patient_phone_numbers()
    {
        $rows = [
            ['Amos', '+256 700-111-222'],
            ['Brenda', '(0700) 111 999'],
            ['Caleb', '256.700.111.333'],
            ['Diana', '700 111 444'],
        ];

        $csv = "NAME,PHONE\n";
        foreach ($rows as [$name, $phone]) {
            $csv .= '"' . $name . '","' . $phone . "\"\n";
        }

        $file = UploadedFile::fake()->createWithContent('phone_clean.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'PHONE' => 'phone',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => 4, 'failed' => 0]]);

        $this->assertEquals('0700111222', Patient::where('first_name', 'Amos')->value('phone'));
        $this->assertEquals('0700111999', Patient::where('first_name', 'Brenda')->value('phone'));
        $this->assertEquals('0700111333', Patient::where('first_name', 'Caleb')->value('phone'));
        $this->assertEquals('0700111444', Patient::where('first_name', 'Diana')->value('phone'));
    }
}

