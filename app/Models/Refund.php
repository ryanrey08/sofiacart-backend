<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Models\Concerns\RedactsSensitiveMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Refund extends Model
{
    use RedactsSensitiveMetadata;

    protected $fillable = [
        'merchant_id',
        'payment_id',
        'order_id',
        'return_request_id',
        'reference',
        'payout_reference',
        'amount',
        'reason',
        'notes',
        'failure_reason',
        'cancel_order',
        'requested_by',
        'idempotency_key',
        'status',
        'refunded_at',
        'reviewed_by',
        'reviewed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancel_order' => 'boolean',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The return this refund was requested for (refunds.return_request_id).
     */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /**
     * The return that was processed with this refund (return_requests.refund_id).
     */
    public function processedReturn(): HasOne
    {
        return $this->hasOne(ReturnRequest::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(RefundStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
