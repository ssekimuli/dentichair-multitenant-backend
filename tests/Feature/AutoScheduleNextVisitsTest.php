<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\Patient;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoScheduleNextVisitsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_automatically_schedule_appointment_for_next_visit()
    {
        $role = RolePermission::create([
            'id' => 1,
            'name' => 'Dentist',
            'permissions' => []
        ]);

        $branch = Branch::create([
            'name' => 'Downtown Clinic',
            'address' => '123 Main St',
            'currency' => 'KES'
        ]);

        $doctor = Employee::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'Active',
        ]);

        $patient = Patient::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '2000-01-15',
            'gender' => 'Male',
            'branch_id' => $branch->id,
            'status' => 'Active',
            'next_visit' => '2026-07-20',
        ]);

        $this->artisan('app:auto-schedule-next-visits')
            ->expectsOutput('Starting auto-scheduling of next visits...')
            ->expectsOutput('Successfully scheduled 1 appointments.')
            ->assertExitCode(0);

        // Verify appointment was created
        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => '2026-07-20',
            'treatment' => 'Routine Checkup',
        ]);

        // Verify patient next_visit is cleared
        $patient->refresh();
        $this->assertNull($patient->next_visit);
    }
}
