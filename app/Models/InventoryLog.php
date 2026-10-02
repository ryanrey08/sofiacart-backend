<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'merchant_id',
        'product_id',
        'product_variant_id',
        'user_id',
        'type',
        'reason',
        'quantity_change',
        'resulting_stock',
        'reference_type',
        'reference_number',
        'supplier',
        'reference_date',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'reference_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
