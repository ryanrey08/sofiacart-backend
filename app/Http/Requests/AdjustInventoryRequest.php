<?php

namespace App\Http\Requests;

use App\Enums\InventoryAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    public const MAX_QUANTITY = 1000000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accepts the Update Stock form (`adjustment_type` + `quantity`) or the original signed
     * `quantity_change` payload; exactly one of the two must be sent.
     */
    public function rules(): array
    {
        $isSet = $this->input('adjustment_type') === InventoryAdjustmentType::Set->value;

        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer'],
            'adjustment_type' => ['required_without:quantity_change', 'prohibits:quantity_change', Rule::enum(InventoryAdjustmentType::class)],
            'quantity' => ['required_with:adjustment_type', 'integer', 'min:'.($isSet ? 0 : 1), 'max:'.self::MAX_QUANTITY],
            'quantity_change' => ['required_without:adjustment_type', 'integer', 'not_in:0', 'between:-'.self::MAX_QUANTITY.','.self::MAX_QUANTITY],
            'reason' => ['required_with:quantity_change', 'nullable', 'string', 'max:255'],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'reference_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function adjustmentType(): InventoryAdjustmentType
    {
        if ($this->filled('adjustment_type')) {
            return InventoryAdjustmentType::from($this->validated('adjustment_type'));
        }

        return $this->integer('quantity_change') > 0 ? InventoryAdjustmentType::Increase : InventoryAdjustmentType::Decrease;
    }

    public function adjustmentQuantity(): int
    {
        return $this->filled('adjustment_type')
            ? (int) $this->validated('quantity')
            : abs((int) $this->validated('quantity_change'));
    }

    /**
     * The field validation errors about the quantity should be reported against.
     */
    public function quantityField(): string
    {
        return $this->filled('adjustment_type') ? 'quantity' : 'quantity_change';
    }
}
