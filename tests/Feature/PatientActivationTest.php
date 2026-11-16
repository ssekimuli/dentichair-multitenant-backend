<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientActivationTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

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

    protected function makePatient(string $name, ?string $source, string $status = 'Inactive', ?string $createdAt = null): Patient
    {
        $patient = Patient::create([
            'first_name' => $name,
            'last_name' => 'Test',
            'phone' => '07' . random_int(10000000, 99999999),
            'age' => 30,
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'status' => $status,
            'registration_source' => $source,
            'branch_id' => $this->branch->id,
        ]);

        if ($createdAt) {
            DB::table('patients')->where('id', $patient->id)->update(['created_at' => $createdAt]);
        }

        return $patient->fresh();
    }

    /** @test */
    public function web_self_registration_creates_an_inactive_patient()
    {
        $response = $this->post('/self-register', [
            'first_name' => 'Amina',
            'last_name' => 'Nakato',
            'phone' => '0772000111',
            'age' => 28,
            'branch_id' => $this->branch->id,
        ]);

        $response->assertRedirect();

        $patient = Patient::where('phone', '0772000111')->firstOrFail();
        $this->assertEquals('Inactive', $patient->status);
        $this->assertEquals('web_self_register', $patient->registration_source);
    }

    /** @test */
    public function web_booking_creates_an_inactive_patient()
    {
        $response = $this->post('/book-appointment', [
            'first_name' => 'Brian',
            'last_name' => 'Ochieng',
            'phone' => '0701000222',
            'age' => 35,
            'branch_id' => $this->branch->id,
            'doctor_id' => $this->employee->id,
            'treatment' => 'Check up',
            'appointment_date' => now()->addDays(3)->format('Y-m-d'),
            'appointment_time' => '10:00',
        ]);

        $response->assertRedirect();

        $patient = Patient::where('phone', '0701000222')->firstOrFail();
        $this->assertEquals('Inactive', $patient->status);
        $this->assertEquals('web_booking', $patient->registration_source);
    }

    /** @test */
    public function pending_activation_lists_only_web_inactive_patients_newest_first()
    {
        $old = $this->makePatient('Old', 'web_booking', 'Inactive', '2026-01-01 10:00:00');
        $recent = $this->makePatient('Recent', 'web_self_register', 'Inactive', '2026-11-10 10:00:00');
        $newest = $this->makePatient('Newest', 'web_booking', 'Inactive', '2026-11-15 09:00:00');
        $this->makePatient('Staff', null, 'Inactive');
        $this->makePatient('Active web', 'web_booking', 'Active');

        $response = $this->actingAs($this->employee)
            ->getJson('/api/patients/pending-activation');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEquals([$newest->id, $recent->id, $old->id], $ids);
    }

    /** @test */
    public function pending_activation_can_filter_by_source()
    {
        $this->makePatient('Booked', 'web_booking');
        $self = $this->makePatient('Self', 'web_self_register');

        $response = $this->actingAs($this->employee)
            ->getJson('/api/patients/pending-activation?registration_source=web_self_register');

        $response->assertOk();
        $this->assertEquals([$self->id], collect($response->json('data'))->pluck('id')->all());
    }

    /** @test */
    public function pending_activation_searches_name_phone_and_email()
    {
        $amina = Patient::create([
            'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone' => '0772555101',
            'email' => 'amina.n@example.com', 'age' => 28, 'date_of_birth' => now()->subYears(28)->format('Y-m-d'),
            'status' => 'Inactive', 'registration_source' => 'web_self_register', 'branch_id' => $this->branch->id,
        ]);
        $brian = Patient::create([
            'first_name' => 'Brian', 'last_name' => 'Ochieng', 'phone' => '0701999222',
            'age' => 35, 'date_of_birth' => now()->subYears(35)->format('Y-m-d'),
            'status' => 'Inactive', 'registration_source' => 'web_booking', 'branch_id' => $this->branch->id,
        ]);

        $search = fn (string $term) => collect($this->actingAs($this->employee)
            ->getJson('/api/patients/pending-activation?search=' . urlencode($term))
            ->json('data'))->pluck('id')->all();

        $this->assertEquals([$amina->id], $search('amina'));
        $this->assertEquals([$brian->id], $search('0701999'));
        $this->assertEquals([$amina->id], $search('amina.n@example'));
        $this->assertEquals([$brian->id], $search('Ochieng'));
        $this->assertEquals([], $search('nobody-matches'));
        $this->assertEquals([], $search('%'));
    }

    /** @test */
    public function activate_sets_the_patient_active()
    {
        $patient = $this->makePatient('Waiting', 'web_booking');

        $response = $this->actingAs($this->employee)
            ->patchJson("/api/patients/{$patient->id}/activate");

        $response->assertOk()->assertJsonPath('status', 'Active');
        $this->assertEquals('Active', $patient->fresh()->status);

        $list = $this->actingAs($this->employee)->getJson('/api/patients/pending-activation');
        $this->assertCount(0, $list->json('data'));
    }

    /** @test */
    public function bulk_activate_reports_activated_already_active_and_missing_ids()
    {
        $a = $this->makePatient('A', 'web_booking');
        $b = $this->makePatient('B', 'web_self_register');
        $c = $this->makePatient('C', 'web_booking', 'Active');
        $missing = 999999;

        $response = $this->actingAs($this->employee)
            ->postJson('/api/patients/activate', [
                'ids' => [$a->id, $b->id, $c->id, $missing, $a->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('activated', 2)
            ->assertJsonPath('already_active', 1)
            ->assertJsonPath('not_found', 1)
            ->assertJsonPath('not_found_ids.0', $missing);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $response->json('activated_ids'));
        $this->assertEquals([$c->id], $response->json('already_active_ids'));
        $this->assertEquals('Active', $a->fresh()->status);
        $this->assertEquals('Active', $b->fresh()->status);

        $list = $this->actingAs($this->employee)->getJson('/api/patients/pending-activation');
        $this->assertCount(0, $list->json('data'));
    }

    /** @test */
    public function bulk_activate_rejects_an_empty_list()
    {
        $this->actingAs($this->employee)
            ->postJson('/api/patients/activate', ['ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);
    }

    /** @test */
    public function activate_returns_404_for_unknown_patient()
    {
        $this->actingAs($this->employee)
            ->patchJson('/api/patients/999999/activate')
            ->assertNotFound();
    }
}
