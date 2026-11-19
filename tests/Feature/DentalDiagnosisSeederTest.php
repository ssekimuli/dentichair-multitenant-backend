<?php

namespace Tests\Feature;

use Database\Seeders\DentalDiagnosisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DentalDiagnosisSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeding_creates_diagnoses_and_running_again_adds_nothing(): void
    {
        $this->seed(DentalDiagnosisSeeder::class);
        $first = DB::table('diagnoses')->count();

        $this->assertGreaterThan(100, $first);
        $this->assertDatabaseHas('diagnoses', ['name' => 'Irreversible pulpitis', 'category' => 'Pulpal Diseases']);

        $this->seed(DentalDiagnosisSeeder::class);

        $this->assertEquals($first, DB::table('diagnoses')->count());
    }
}
