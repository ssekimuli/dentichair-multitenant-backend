<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Payroll;

class PayrollPolicy
{
    /**
     * Determine whether the employee can approve the payroll.
     */
    public function approve(Employee $user, Payroll $payroll): bool
    {
        $role = strtolower($user->role);
        return in_array($role, ['super admin', 'hr manager', 'admin', 'hr']);
    }
}
