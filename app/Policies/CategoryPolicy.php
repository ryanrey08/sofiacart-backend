<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Determine whether the user can view the category.
     */
    public function view(User $user, Category $category): bool
    {
        return $this->ownsCategory($user, $category);
    }

    /**
     * Determine whether the user can update the category.
     */
    public function update(User $user, Category $category): bool
    {
        return $this->ownsCategory($user, $category);
    }

    /**
     * Determine whether the user can delete the category.
     */
    public function delete(User $user, Category $category): bool
    {
        return $this->ownsCategory($user, $category);
    }

    protected function ownsCategory(User $user, Category $category): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $merchantId = $user->merchant?->id;

        return $merchantId !== null && $merchantId === $category->merchant_id;
    }
}
