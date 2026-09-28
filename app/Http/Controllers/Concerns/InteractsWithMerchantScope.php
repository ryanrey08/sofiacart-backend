<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait InteractsWithMerchantScope
{
    protected function isAdmin(Request $request): bool
    {
        return $request->user()?->role === UserRole::Admin;
    }

    protected function currentMerchant(Request $request): ?Merchant
    {
        return $request->user()?->merchant;
    }

    protected function scopeMerchant(Builder $query, Request $request, string $column = 'merchant_id'): Builder
    {
        if (! $this->isAdmin($request)) {
            $query->where($column, $this->requiredMerchantId($request));
        }

        return $query;
    }

    protected function merchantIdForWrite(Request $request, ?int $requestedMerchantId = null): int
    {
        if ($this->isAdmin($request)) {
            if ($requestedMerchantId) {
                return $requestedMerchantId;
            }

            throw ValidationException::withMessages([
                'merchant_id' => ['A merchant_id is required for admin write operations.'],
            ]);
        }

        return $this->requiredMerchantId($request);
    }

    protected function requiredMerchantId(Request $request): int
    {
        $merchantId = $this->currentMerchant($request)?->id;

        if (! $merchantId) {
            throw ValidationException::withMessages([
                'merchant' => ['The authenticated user is not linked to a merchant account.'],
            ]);
        }

        return $merchantId;
    }

    protected function userOrFail(Request $request): User
    {
        return $request->user() ?? throw ValidationException::withMessages([
            'user' => ['The authenticated user could not be resolved.'],
        ]);
    }
}
