<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run()
    {
        // Get role mappings from role_permissions table
        $roles = RolePermission::all()->keyBy('name');

        $employees = [
            [
                'id' => 1,
                'name' => 'Dr. Lisa Cuddy', 
                'avatar' => 'https://picsum.photos/seed/emp1/200/200', 
                'email' => 'lisa.cuddy@example.com', 
                'password' => Hash::make('password'),
                'role' => 'Super Admin', 
                'role_id' => $roles['Super Admin']->id ?? 1, // Use numeric role_id
                'status' => 'Active', 
                'salary' => 150000, 
                'hire_date' => '2020-01-15', 
                'branch_id' => 1
            ],
            [
                'id' => 2,
                'name' => 'James Wilson', 
                'avatar' => 'https://picsum.photos/seed/emp2/200/200', 
                'email' => 'james.wilson@example.com', 
                'password' => Hash::make('password'),
                'role' => 'Hygienist', 
                'role_id' => $roles['Hygienist']->id ?? 2, // Use numeric role_id
                'status' => 'Active', 
                'salary' => 75000, 
                'hire_date' => '2021-03-20', 
                'branch_id' => 1
            ],
            [
                'id' => 3,
                'name' => 'Allison Cameron', 
                'avatar' => 'https://picsum.photos/seed/emp3/200/200', 
                'email' => 'allison.cameron@example.com', 
                'password' => Hash::make('password'),
                'role' => 'Receptionist', 
                'role_id' => $roles['Receptionist']->id ?? 3, // Use numeric role_id
                'status' => 'Active', 
                'salary' => 50000, 
                'hire_date' => '2022-07-01', 
                'branch_id' => 2
            ],
            [
                'id' => 4,
                'name' => 'Robert Chase', 
                'avatar' => 'https://picsum.photos/seed/emp4/200/200', 
                'email' => 'robert.chase@example.com', 
                'password' => Hash::make('password'),
                'role' => 'Assistant', 
                'role_id' => $roles['Assistant']->id ?? 4, // Use numeric role_id
                'status' => 'On Leave', 
                'salary' => 55000, 
                'hire_date' => '2021-09-10', 
                'branch_id' => 2
            ],
            [
                'id' => 5,
                'name' => 'Dr. Eric Foreman', 
                'avatar' => 'https://picsum.photos/seed/emp5/200/200', 
                'email' => 'eric.foreman@example.com', 
                'password' => Hash::make('password'),
                'role' => 'Dentist', 
                'role_id' => $roles['Dentist']->id ?? 5, // Use numeric role_id
                'status' => 'Active', 
                'salary' => 120000, 
                'hire_date' => '2019-08-01', 
                'branch_id' => 2
            ],
        ];

        foreach ($employees as $employee) {
            Employee::create($employee);
        }
    }
}