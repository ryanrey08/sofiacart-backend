<?php

namespace App\Policies;

use App\Admin\AdminPermissionRegistry;
use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW, $refund);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_REFUND);
    }

    public function update(User $user, Refund $refund): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_REFUND, $refund);
    }

    public function delete(User $user, Refund $refund): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_REFUND, $refund);
    }

    /**
     * Admins need an admin-scoped token and the permission; merchants may only act on their own records.
     */
    protected function allows(User $user, string $adminPermission, ?Refund $refund = null): bool
    {
        if ($user->isAdmin()) {
            return $user->currentAccessToken()?->can('admin') === true
                && $user->hasAdminPermission($adminPermission);
        }

        $merchantId = $user->merchant?->id;

        return $merchantId !== null && ($refund === null || $merchantId === $refund->merchant_id);
    }
}
