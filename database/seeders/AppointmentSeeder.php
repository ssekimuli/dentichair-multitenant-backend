<?php

namespace Database\Seeders;

use App\Models\Appointment;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run()
    {
        $today = now()->format('Y-m-d');

        $appointments = [
            ['id' => 'a1', 'patient_id' => '1', 'patient_name' => 'John Doe', 'doctor_id' => 'emp1', 'doctor_name' => 'Dr. Lisa Cuddy', 'treatment' => 'Check-up', 'status' => 'Scheduled', 'date' => '2024-07-20', 'time' => '10:00 AM', 'branch_id' => 'b1', 'token_number' => 'JDLC1'],
            ['id' => 'a2', 'patient_id' => '2', 'patient_name' => 'Jane Smith', 'doctor_id' => 'emp5', 'doctor_name' => 'Dr. Eric Foreman', 'treatment' => 'Cleaning', 'status' => 'Scheduled', 'date' => '2024-07-21', 'time' => '11:30 AM', 'branch_id' => 'b2', 'token_number' => 'JSEF2'],
            ['id' => 'a3', 'patient_id' => '4', 'patient_name' => 'Mike Williams', 'doctor_id' => 'emp5', 'doctor_name' => 'Dr. Eric Foreman', 'treatment' => 'Filling', 'status' => 'Completed', 'date' => '2024-07-15', 'time' => '02:00 PM', 'branch_id' => 'b2', 'token_number' => 'MWEF3'],
            ['id' => 'a4', 'patient_id' => '5', 'patient_name' => 'Sarah Brown', 'doctor_id' => 'emp1', 'doctor_name' => 'Dr. Lisa Cuddy', 'treatment' => 'Consultation', 'status' => 'Cancelled', 'date' => '2024-07-14', 'time' => '09:00 AM', 'branch_id' => 'b1', 'token_number' => 'SBLC4'],
            ['id' => 'a5', 'patient_id' => '3', 'patient_name' => 'Peter Jones', 'doctor_id' => 'emp1', 'doctor_name' => 'Dr. Lisa Cuddy', 'treatment' => 'Periodontal Scaling', 'status' => 'Scheduled', 'date' => $today, 'time' => '09:00 AM', 'branch_id' => 'b1', 'token_number' => 'PJLC5'],
            ['id' => 'a6', 'patient_id' => '1', 'patient_name' => 'John Doe', 'doctor_id' => 'emp1', 'doctor_name' => 'Dr. Lisa Cuddy', 'treatment' => 'Crown Follow-up', 'status' => 'Waiting', 'date' => $today, 'time' => '09:30 AM', 'branch_id' => 'b1', 'token_number' => 'JDLC6'],
        ];

        foreach ($appointments as $appointment) {
            Appointment::create($appointment);
        }
    }
}