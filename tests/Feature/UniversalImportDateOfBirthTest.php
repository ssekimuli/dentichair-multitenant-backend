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

class UniversalImportDateOfBirthTest extends TestCase
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

    private function import(string $csv, string $mode = 'insert')
    {
        $file = UploadedFile::fake()->createWithContent('dob.csv', $csv);

        return $this->actingAs($this->employee)
            ->postJson('/api/import/execute', [
                'file' => $file,
                'table' => 'patients',
                'mode' => $mode,
                'async' => false,
                'mapping' => [
                    'NAME' => 'first_name',
                    'PHONE' => 'phone',
                    'DOB' => 'date_of_birth',
                ],
            ]);
    }

    /** @test */
    public function it_converts_a_real_date_of_birth_to_year_month_day()
    {
        $csv = "NAME,PHONE,DOB\n";
        $csv .= "\"Amos\",\"0700111222\",\"12/05/1995\"\n";

        $this->import($csv)->assertJson(['data' => ['imported' => 1, 'failed' => 0]]);

        $this->assertEquals('1995-05-12', Patient::where('first_name', 'Amos')->first()->date_of_birth->toDateString());
    }

    /** @test */
    public function it_does_not_fail_when_date_of_birth_is_a_word_like_adult_or_child()
    {
        $csv = "NAME,PHONE,DOB\n";
        $csv .= "\"Brenda\",\"0700111333\",\"Adult\"\n";
        $csv .= "\"Caleb\",\"0700111444\",\"Child\"\n";

        $this->import($csv)->assertJson(['data' => ['imported' => 2, 'failed' => 0]]);

        // Adult -> age 18, Child -> age 10; date of birth is today minus that age
        $brenda = Patient::where('first_name', 'Brenda')->firstOrFail();
        $this->assertEquals(18, $brenda->age);
        $this->assertEquals(now()->subYears(18)->toDateString(), $brenda->date_of_birth->toDateString());

        $caleb = Patient::where('first_name', 'Caleb')->firstOrFail();
        $this->assertEquals(10, $caleb->age);
        $this->assertEquals(now()->subYears(10)->toDateString(), $caleb->date_of_birth->toDateString());
    }

    /** @test */
    public function it_keeps_an_existing_date_of_birth_when_the_file_only_says_adult()
    {
        Patient::create([
            'first_name' => 'Diana', 'last_name' => 'Nakato', 'phone' => '0700111555',
            'branch_id' => $this->branch->id, 'gender' => 'Female',
            'date_of_birth' => '1980-03-04', 'status' => 'Active',
        ]);

        $csv = "NAME,PHONE,DOB\n";
        $csv .= "\"Diana\",\"0700111555\",\"Adult\"\n";

        $this->import($csv, 'upsert')->assertJson(['data' => ['failed' => 0]]);

        $diana = Patient::where('phone', '0700111555')->firstOrFail();
        $this->assertEquals('1980-03-04', $diana->date_of_birth->toDateString());
        $this->assertNull($diana->getRawOriginal('age'));
    }
}
