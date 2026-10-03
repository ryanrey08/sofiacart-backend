<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    protected $fillable = ['merchant_id', 'order_id', 'customer_id', 'refund_id', 'status', 'reason', 'notes', 'evidence', 'amount'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'amount' => 'decimal:2'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * Refund requests raised for this return (refunds.return_request_id).
     */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
