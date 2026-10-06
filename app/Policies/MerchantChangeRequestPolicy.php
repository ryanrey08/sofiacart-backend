<?php

namespace App\Policies;

use App\Admin\AdminPermissionRegistry;
use App\Models\MerchantChangeRequest;
use App\Models\User;

/**
 * Merchants submit and read their own profile change requests; only admins with
 * `merchants.manage` can approve or reject them. A merchant can never review a request.
 */
class MerchantChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            ? $this->adminCan($user, AdminPermissionRegistry::MERCHANTS_VIEW)
            : $user->merchant !== null;
    }

    public function view(User $user, MerchantChangeRequest $changeRequest): bool
    {
        return $user->isAdmin()
            ? $this->adminCan($user, AdminPermissionRegistry::MERCHANTS_VIEW)
            : $user->merchant?->id === $changeRequest->merchant_id;
    }

    public function create(User $user): bool
    {
        return ! $user->isAdmin() && $user->merchant !== null;
    }

    /**
     * Only the merchant who owns the request may withdraw it.
     */
    public function withdraw(User $user, MerchantChangeRequest $changeRequest): bool
    {
        return ! $user->isAdmin() && $user->merchant?->id === $changeRequest->merchant_id;
    }

    public function review(User $user, MerchantChangeRequest $changeRequest): bool
    {
        return $user->isAdmin() && $this->adminCan($user, AdminPermissionRegistry::MERCHANTS_MANAGE);
    }

    protected function adminCan(User $user, string $permission): bool
    {
        return $user->currentAccessToken()?->can('admin') === true
            && $user->hasAdminPermission($permission);
    }
}
