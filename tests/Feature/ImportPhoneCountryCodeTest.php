<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Patient;
use App\Services\Import\ImportValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPhoneCountryCodeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main Dental Clinic', 'address' => 'Kampala Road']);

        Patient::create([
            'first_name' => 'Ikhlas',
            'last_name' => 'Ali',
            'phone' => '0700111222',
            'age' => 30,
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'status' => 'Active',
            'branch_id' => $this->branch->id,
        ]);
    }

    private function matcher(): ImportValidationService
    {
        return app(ImportValidationService::class);
    }

    /** @test */
    public function local_number_matches_a_patient_stored_with_leading_zero()
    {
        $this->assertNotNull($this->matcher()->findPatientByPhone('0700111222', $this->branch->id));
    }

    /** @test */
    public function international_number_matches_a_patient_stored_with_leading_zero()
    {
        foreach (['+256700111222', '+256 700 111 222', '256700111222', '+256-700-111-222', '+256 (0)700 111222'] as $phone) {
            $this->assertNotNull(
                $this->matcher()->findPatientByPhone($phone, $this->branch->id),
                "Phone [{$phone}] should match the patient stored as 0700111222"
            );
        }
    }

    /** @test */
    public function international_number_matches_when_the_row_name_has_a_title()
    {
        // Appointment sheets often write titles such as "MS" or "MR" before the name
        $this->assertNotNull(
            $this->matcher()->findPatientByPhone('+256700111222', $this->branch->id, 'MS IKHLAS')
        );
    }
}
