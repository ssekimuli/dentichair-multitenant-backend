<?php

namespace Database\Seeders;

use App\Models\Payroll;
use App\Models\InventoryItem;
use App\Models\LaboratoryOrder;
use App\Models\LeaveRequest;
use App\Models\StaffAdvance;
use App\Models\Expense;
use App\Models\ChartData;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class ManagementSeeder extends Seeder
{
    public function run()
    {
        // $this->createPayrolls();
        // $this->createInventory();
        // $this->createLabOrders();
        // $this->createLeaveRequests();
        // $this->createStaffAdvances();
        // $this->createExpenses();
        // $this->createChartData();
        $this->createRoles();
    }


    private function createRoles()
    {
        $roles = [
            [
                'name' => 'Super Admin',
                'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'patients:delete', 'charting:read', 'charting:update', 'periodontal:read', 'periodontal:update', 'appointments:read', 'appointments:create', 'appointments:update', 'appointments:delete', 'billing:read', 'billing:create', 'billing:update', 'billing:delete', 'accounting:read','accounting:read_revenue', 'accounting:create', 'inventory:read', 'inventory:create', 'inventory:update', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'hrm:read', 'hrm:create', 'hrm:update', 'hrm:delete', 'branches:read', 'branches:create', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Accountant',
                'permissions' => ['dashboard:read', 'billing:read', 'billing:create', 'billing:update', 'billing:delete', 'accounting:read', 'accounting:create', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'HR Manager',
                'permissions' => ['dashboard:read', 'hrm:read', 'hrm:create', 'hrm:update', 'hrm:delete', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Dentist',
                'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'charting:read', 'charting:update', 'periodontal:read', 'periodontal:update', 'appointments:read', 'appointments:create', 'appointments:update', 'billing:read', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Hygienist',
                'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'charting:read', 'charting:update', 'periodontal:read', 'periodontal:update', 'appointments:read', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Receptionist',
                'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'appointments:read', 'appointments:create', 'appointments:update', 'appointments:delete', 'billing:read', 'billing:create', 'billing:update', 'inventory:read', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Dental Assistant',
                'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'charting:read', 'periodontal:read', 'appointments:read', 'inventory:read', 'inventory:create', 'inventory:update', 'laboratory:read', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Lab Technician',
                'permissions' => ['dashboard:read', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'patients:read', 'inventory:read', 'billing:read', 'profile:read', 'profile:update']
            ],
            [
                'name' => 'Lab Manager',
                'permissions' => ['dashboard:read', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'laboratory:delete', 'patients:read', 'inventory:read', 'inventory:create', 'inventory:update', 'billing:read', 'billing:create', 'reports:read', 'profile:read', 'profile:update']
            ]
        ];

        foreach ($roles as $role) {
            RolePermission::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}