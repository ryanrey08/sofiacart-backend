<?php

namespace App\Policies;

use App\Admin\AdminPermissionRegistry;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view inventory, optionally for a specific product.
     */
    public function viewInventory(User $user, ?Product $product = null): bool
    {
        return $this->canAccessInventory($user, $product);
    }

    /**
     * Determine whether the user can adjust the product's stock.
     */
    public function manageInventory(User $user, Product $product): bool
    {
        return $this->canAccessInventory($user, $product);
    }

    /**
     * Admins need an admin-scoped token and the inventory permission; merchants may only
     * touch their own products.
     */
    protected function canAccessInventory(User $user, ?Product $product): bool
    {
        if ($user->isAdmin()) {
            return $user->currentAccessToken()?->can('admin') === true
                && $user->hasAdminPermission(AdminPermissionRegistry::PRODUCTS_INVENTORY);
        }

        $merchantId = $user->merchant?->id;

        return $merchantId !== null && ($product === null || $merchantId === $product->merchant_id);
    }
}
