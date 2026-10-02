<?php

namespace App\Policies;

use App\Admin\AdminPermissionRegistry;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW, $payment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_MANAGE);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_MANAGE, $payment);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_MANAGE, $payment);
    }

    /**
     * Admins need an admin-scoped token and the permission; merchants may only act on their own records.
     */
    protected function allows(User $user, string $adminPermission, ?Payment $payment = null): bool
    {
        if ($user->isAdmin()) {
            return $user->currentAccessToken()?->can('admin') === true
                && $user->hasAdminPermission($adminPermission);
        }

        $merchantId = $user->merchant?->id;

        return $merchantId !== null && ($payment === null || $merchantId === $payment->merchant_id);
    }
}
