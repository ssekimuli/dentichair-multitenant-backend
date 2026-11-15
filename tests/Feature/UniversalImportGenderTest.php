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

class UniversalImportGenderTest extends TestCase
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
    public function it_converts_gender_spellings_to_male_female_other()
    {
        $rows = [
            ['Amos', '0700000001', 'M'],
            ['Brenda', '0700000002', 'f'],
            ['Caleb', '0700000003', 'boy'],
            ['Diana', '0700000004', 'Woman'],
            ['Eli', '0700000005', 'x'],
        ];

        $csv = "NAME,PHONE,GENDER\n";
        foreach ($rows as [$name, $phone, $gender]) {
            $csv .= "{$name},{$phone},{$gender}\n";
        }

        $file = UploadedFile::fake()->createWithContent('gender_test.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'PHONE' => 'phone',
                    'GENDER' => 'gender',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => 5, 'failed' => 0]]);

        $this->assertEquals('Male', Patient::where('first_name', 'Amos')->value('gender'));
        $this->assertEquals('Female', Patient::where('first_name', 'Brenda')->value('gender'));
        $this->assertEquals('Male', Patient::where('first_name', 'Caleb')->value('gender'));
        $this->assertEquals('Female', Patient::where('first_name', 'Diana')->value('gender'));
        $this->assertEquals('Other', Patient::where('first_name', 'Eli')->value('gender'));
    }

    /** @test */
    public function it_rejects_unrecognized_gender_values()
    {
        $csv = "NAME,PHONE,GENDER\n";
        $csv .= "Frank,0700000009,Unknown-thing\n";

        $file = UploadedFile::fake()->createWithContent('gender_bad.csv', $csv);

        $response = $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => 'insert',
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'PHONE' => 'phone',
                    'GENDER' => 'gender',
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['imported' => 0, 'failed' => 1]]);

        $this->assertDatabaseCount('patients', 0);
    }
}
