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
                'name' => 'Dr. Lisa', 
                'avatar' => 'https://picsum.photos/seed/emp1/200/200', 
                'email' => 'ssekimuliandrew321@gmail.com', 
                'password' => Hash::make('W6kMKH'),
                'role' => 'Super Admin', 
                'role_id' => $roles['Super Admin']->id ?? 1, // Use numeric role_id
                'status' => 'Active', 
                'salary' => 150000, 
                'hire_date' => '2020-01-15', 
                'branch_id' => 1
            ]
        ];

        foreach ($employees as $employee) {
            Employee::updateOrCreate(['id' => $employee['id']], $employee);
        }
    }
}