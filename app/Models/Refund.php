<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'merchant_id',
        'payment_id',
        'order_id',
        'reference',
        'amount',
        'reason',
        'status',
        'refunded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
