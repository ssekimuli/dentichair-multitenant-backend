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
        $this->createPayrolls();
        $this->createInventory();
        $this->createLabOrders();
        $this->createLeaveRequests();
        $this->createStaffAdvances();
        $this->createExpenses();
        $this->createChartData();
        $this->createRoles();
    }

    private function createPayrolls()
    {
        $payrolls = [
            ['id' => 'pay1', 'employee_id' => 'emp1', 'employee_name' => 'Dr. Lisa Cuddy', 'pay_period' => '2024-06-01 to 2024-06-15', 'gross_pay' => 6250, 'deductions' => 1200, 'net_pay' => 5050, 'pay_date' => '2024-06-15', 'status' => 'Paid', 'branch_id' => 'b1'],
            ['id' => 'pay2', 'employee_id' => 'emp2', 'employee_name' => 'James Wilson', 'pay_period' => '2024-06-01 to 2024-06-15', 'gross_pay' => 3125, 'deductions' => 600, 'net_pay' => 2525, 'pay_date' => '2024-06-15', 'status' => 'Paid', 'branch_id' => 'b1'],
            ['id' => 'pay3', 'employee_id' => 'emp3', 'employee_name' => 'Allison Cameron', 'pay_period' => '2024-06-16 to 2024-06-30', 'gross_pay' => 2083.33, 'deductions' => 400, 'net_pay' => 1683.33, 'pay_date' => '2024-06-30', 'status' => 'Pending', 'branch_id' => 'b2'],
            ['id' => 'pay4', 'employee_id' => 'emp4', 'employee_name' => 'Robert Chase', 'pay_period' => '2024-06-16 to 2024-06-30', 'gross_pay' => 2291.67, 'deductions' => 450, 'net_pay' => 1841.67, 'pay_date' => '2024-06-30', 'status' => 'Pending', 'branch_id' => 'b2'],
        ];

        foreach ($payrolls as $payroll) {
            Payroll::create($payroll);
        }
    }

    private function createInventory()
    {
        $inventory = [
            ['id' => 'item1', 'name' => 'Gloves (Box of 100)', 'supplier' => 'Dental Supplies Co.', 'stock' => 50, 'reorder_level' => 20, 'price' => 15.00, 'status' => 'In Stock', 'branch_id' => 'b1'],
            ['id' => 'item2', 'name' => 'Dental Masks (Box of 50)', 'supplier' => 'MediWear', 'stock' => 15, 'reorder_level' => 10, 'price' => 25.00, 'status' => 'Low Stock', 'branch_id' => 'b1'],
            ['id' => 'item3', 'name' => 'Composite Resin (Syringe)', 'supplier' => '3M Dental', 'stock' => 100, 'reorder_level' => 50, 'price' => 85.00, 'status' => 'In Stock', 'branch_id' => 'b2'],
            ['id' => 'item4', 'name' => 'Anesthetic Cartridges', 'supplier' => 'PharmaPlus', 'stock' => 5, 'reorder_level' => 20, 'price' => 55.00, 'status' => 'Low Stock', 'branch_id' => 'b2'],
            ['id' => 'item5', 'name' => 'X-Ray Film', 'supplier' => 'Kodak', 'stock' => 0, 'reorder_level' => 10, 'price' => 120.00, 'status' => 'Out of Stock', 'branch_id' => 'b1'],
        ];

        foreach ($inventory as $item) {
            InventoryItem::create($item);
        }
    }

    private function createLabOrders()
    {
        $labOrders = [
            ['id' => 'lab1', 'patient_id' => '1', 'patient_name' => 'John Doe', 'lab' => 'Precision Dental Labs', 'item' => 'Zirconia Crown', 'tooth' => 48, 'status' => 'Sent', 'sent_date' => '2024-06-02', 'due_date' => '2024-06-16', 'branch_id' => 'b1'],
            ['id' => 'lab2', 'patient_id' => '2', 'patient_name' => 'Jane Smith', 'lab' => 'Aesthetic Creations', 'item' => 'Night Guard', 'status' => 'Received', 'sent_date' => '2024-05-15', 'due_date' => '2024-05-30', 'branch_id' => 'b2'],
            ['id' => 'lab3', 'patient_id' => '4', 'patient_name' => 'Mike Williams', 'lab' => 'Precision Dental Labs', 'item' => 'Inlay', 'tooth' => 15, 'status' => 'Completed', 'sent_date' => '2024-07-16', 'due_date' => '2024-07-30', 'branch_id' => 'b2'],
            ['id' => 'lab4', 'patient_id' => '1', 'patient_name' => 'John Doe', 'lab' => 'City Labs', 'item' => 'Denture Repair', 'status' => 'Cancelled', 'sent_date' => '2024-07-01', 'due_date' => '2024-07-10', 'branch_id' => 'b1'],
        ];

        foreach ($labOrders as $order) {
            LaboratoryOrder::create($order);
        }
    }

    private function createLeaveRequests()
    {
        $leaveRequests = [
            ['id' => 'leave1', 'employee_id' => 'emp2', 'employee_name' => 'James Wilson', 'leave_type' => 'Annual', 'start_date' => '2024-08-01', 'end_date' => '2024-08-05', 'reason' => 'Family vacation', 'status' => 'Approved', 'branch_id' => 'b1'],
            ['id' => 'leave2', 'employee_id' => 'emp4', 'employee_name' => 'Robert Chase', 'leave_type' => 'Sick', 'start_date' => '2024-07-18', 'end_date' => '2024-07-18', 'reason' => 'Feeling unwell', 'status' => 'Approved', 'branch_id' => 'b2'],
            ['id' => 'leave3', 'employee_id' => 'emp3', 'employee_name' => 'Allison Cameron', 'leave_type' => 'Annual', 'start_date' => '2024-09-01', 'end_date' => '2024-09-03', 'reason' => 'Personal time', 'status' => 'Pending', 'branch_id' => 'b2'],
            ['id' => 'leave4', 'employee_id' => 'emp5', 'employee_name' => 'Dr. Eric Foreman', 'leave_type' => 'Unpaid', 'start_date' => '2024-08-10', 'end_date' => '2024-08-10', 'reason' => 'Conference', 'status' => 'Rejected', 'branch_id' => 'b2'],
        ];

        foreach ($leaveRequests as $request) {
            LeaveRequest::create($request);
        }
    }

    private function createStaffAdvances()
    {
        $advances = [
            ['id' => 'adv1', 'employee_id' => 'emp3', 'employee_name' => 'Allison Cameron', 'amount' => 500, 'date' => '2024-07-01', 'reason' => 'Emergency', 'status' => 'Approved', 'branch_id' => 'b2'],
            ['id' => 'adv2', 'employee_id' => 'emp5', 'employee_name' => 'Dr. Eric Foreman', 'amount' => 1000, 'date' => '2024-07-10', 'reason' => 'Travel expenses', 'status' => 'Pending', 'branch_id' => 'b2'],
            ['id' => 'adv3', 'employee_id' => 'emp2', 'employee_name' => 'James Wilson', 'amount' => 200, 'date' => '2024-06-20', 'reason' => 'Personal', 'status' => 'Rejected', 'branch_id' => 'b1'],
        ];

        foreach ($advances as $advance) {
            StaffAdvance::create($advance);
        }
    }

    private function createExpenses()
    {
        $expenses = [
            ['id' => 'exp1', 'branch_id' => 'b1', 'date' => '2024-06-01', 'category' => 'Rent', 'description' => 'June Rent - Downtown Clinic', 'amount' => 5000],
            ['id' => 'exp2', 'branch_id' => 'b1', 'date' => '2024-06-05', 'category' => 'Utilities', 'description' => 'Electricity Bill', 'amount' => 450],
            ['id' => 'exp3', 'branch_id' => 'b1', 'date' => '2024-06-10', 'category' => 'Supplies', 'description' => 'Order from Dental Supplies Co.', 'amount' => 1250],
            ['id' => 'exp4', 'branch_id' => 'b2', 'date' => '2024-06-01', 'category' => 'Rent', 'description' => 'June Rent - Uptown Dental', 'amount' => 6500],
            ['id' => 'exp5', 'branch_id' => 'b2', 'date' => '2024-06-06', 'category' => 'Utilities', 'description' => 'Water & Gas Bill', 'amount' => 300],
        ];

        foreach ($expenses as $expense) {
            Expense::create($expense);
        }
    }

    private function createChartData()
    {
        $chartData = [
            ['month' => 'January', 'revenue' => 18600, 'branch_id' => 'b1'],
            ['month' => 'February', 'revenue' => 30500, 'branch_id' => 'b1'],
            ['month' => 'March', 'revenue' => 23700, 'branch_id' => 'b1'],
            ['month' => 'April', 'revenue' => 27800, 'branch_id' => 'b1'],
            ['month' => 'May', 'revenue' => 45231, 'branch_id' => 'b1'],
            ['month' => 'June', 'revenue' => 34900, 'branch_id' => 'b1'],
            ['month' => 'January', 'revenue' => 15000, 'branch_id' => 'b2'],
            ['month' => 'February', 'revenue' => 28000, 'branch_id' => 'b2'],
            ['month' => 'March', 'revenue' => 21000, 'branch_id' => 'b2'],
            ['month' => 'April', 'revenue' => 25000, 'branch_id' => 'b2'],
            ['month' => 'May', 'revenue' => 41000, 'branch_id' => 'b2'],
            ['month' => 'June', 'revenue' => 32000, 'branch_id' => 'b2'],
        ];

        foreach ($chartData as $data) {
            ChartData::create($data);
        }
    }

    private function createRoles()
    {
        $roles = [
            ['id' => 'Super Admin', 'name' => 'Super Admin', 'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'patients:delete', 'charting:read', 'charting:update', 'appointments:read', 'appointments:create', 'appointments:update', 'appointments:delete', 'billing:read', 'billing:create', 'billing:update', 'billing:delete', 'accounting:read', 'accounting:create', 'inventory:read', 'inventory:create', 'inventory:update', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'hrm:read', 'hrm:create', 'hrm:update', 'hrm:delete', 'branches:read', 'branches:create', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']],
            ['id' => 'Account', 'name' => 'Accountant', 'permissions' => ['dashboard:read', 'billing:read', 'billing:create', 'billing:update', 'billing:delete', 'accounting:read', 'accounting:create', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']],
            ['id' => 'HR', 'name' => 'HR Manager', 'permissions' => ['dashboard:read', 'hrm:read', 'hrm:create', 'hrm:update', 'hrm:delete', 'reports:read', 'settings:manage', 'profile:read', 'profile:update']],
            ['id' => 'Dentist', 'name' => 'Dentist', 'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'charting:read', 'charting:update', 'appointments:read', 'appointments:create', 'appointments:update', 'billing:read', 'laboratory:read', 'laboratory:create', 'laboratory:update', 'profile:read', 'profile:update']],
            ['id' => 'Hygienist', 'name' => 'Hygienist', 'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'charting:read', 'charting:update', 'appointments:read', 'profile:read', 'profile:update']],
            ['id' => 'Receptionist', 'name' => 'Receptionist', 'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'patients:create', 'patients:update', 'appointments:read', 'appointments:create', 'appointments:update', 'appointments:delete', 'billing:read', 'billing:create', 'billing:update', 'inventory:read', 'profile:read', 'profile:update']],
            ['id' => 'Assistant', 'name' => 'Dental Assistant', 'permissions' => ['dashboard:read', 'queue:read', 'patients:read', 'charting:read', 'appointments:read', 'inventory:read', 'inventory:create', 'inventory:update', 'laboratory:read', 'profile:read', 'profile:update']]
        ];

        foreach ($roles as $role) {
            RolePermission::create($role);
        }
    }
}