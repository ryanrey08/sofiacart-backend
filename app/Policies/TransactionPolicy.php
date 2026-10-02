<?php

namespace App\Policies;

use App\Admin\AdminPermissionRegistry;
use App\Models\Transaction;
use App\Models\User;

/**
 * Transactions are written only by the payment/refund services, so there are no create or
 * delete abilities; "update" covers the metadata-only annotation endpoint.
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_VIEW, $transaction);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $this->allows($user, AdminPermissionRegistry::PAYMENTS_MANAGE, $transaction);
    }

    /**
     * Admins need an admin-scoped token and the permission; merchants may only act on their own records.
     */
    protected function allows(User $user, string $adminPermission, ?Transaction $transaction = null): bool
    {
        if ($user->isAdmin()) {
            return $user->currentAccessToken()?->can('admin') === true
                && $user->hasAdminPermission($adminPermission);
        }

        $merchantId = $user->merchant?->id;

        return $merchantId !== null && ($transaction === null || $merchantId === $transaction->merchant_id);
    }
}
