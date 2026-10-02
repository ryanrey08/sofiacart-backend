<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\RedactsSensitiveMetadata;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, RedactsSensitiveMetadata;

    protected $fillable = [
        'merchant_id',
        'order_id',
        'reference',
        'gateway_reference',
        'gateway',
        'method',
        'status',
        'amount',
        'currency',
        'paid_at',
        'notes',
        'attachments',
        'failure_reason',
        'expires_at',
        'verified_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'attachments' => 'array',
            'metadata' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
