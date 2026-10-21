<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MerchantDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication and merchant verification are enforced by route middleware
        // (auth:sanctum, merchant.verified); tenant scoping happens in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
