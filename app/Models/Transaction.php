<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\RedactsSensitiveMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use RedactsSensitiveMetadata;

    protected $fillable = [
        'merchant_id',
        'payment_id',
        'refund_id',
        'order_id',
        'reference',
        'source_key',
        'type',
        'status',
        'amount',
        'description',
        'transacted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'transacted_at' => 'datetime',
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

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
