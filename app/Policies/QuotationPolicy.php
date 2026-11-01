<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Quotation;

class QuotationPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before($user, string $ability): ?bool
    {
        if ($user instanceof Employee) {
            $normalizedRole = strtolower(trim($user->role ?? ''));
            if (in_array($normalizedRole, ['super admin', 'admin', 'super_admin', 'accountant', 'dentist', 'doctor', 'receptionist'])) {
                return true;
            }
        }
        return null;
    }

    public function viewAny($user): bool
    {
        return $this->checkUserPermission($user, ['quotations.view', 'quotations:read', 'billing:read', 'billing:create']);
    }

    public function view($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.view', 'quotations:read', 'billing:read']);
    }

    public function create($user): bool
    {
        return $this->checkUserPermission($user, ['quotations.create', 'quotations:create', 'billing:create', 'laboratory:create']);
    }

    public function update($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.update', 'quotations:update', 'billing:update']);
    }

    public function delete($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.delete', 'quotations:delete', 'billing:delete']);
    }

    public function send($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.send', 'quotations:send', 'billing:update', 'billing:create']);
    }

    public function approve($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.approve', 'quotations:approve', 'billing:update']);
    }

    public function reject($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.reject', 'quotations:reject', 'billing:update']);
    }

    public function convert($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.convert', 'quotations:convert', 'billing:create']);
    }

    public function cancel($user, Quotation $quotation): bool
    {
        if ($user->branch_id && $user->branch_id != $quotation->branch_id) {
            return false;
        }
        return $this->checkUserPermission($user, ['quotations.cancel', 'quotations:cancel', 'billing:delete', 'billing:update']);
    }

    private function checkUserPermission($user, array $permissions): bool
    {
        if (!method_exists($user, 'hasPermission')) {
            return true;
        }

        foreach ($permissions as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }
}

