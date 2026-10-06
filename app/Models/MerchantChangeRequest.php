<?php

namespace App\Models;

use App\Enums\MerchantChangeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's requested profile edit. The values in `changes` are applied to the live merchant
 * record only when an admin approves the request (see MerchantProfileChangeService).
 */
class MerchantChangeRequest extends Model
{
    protected $fillable = [
        'merchant_id',
        'status',
        'changes',
        'original',
        'files',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MerchantChangeRequestStatus::class,
            'changes' => 'array',
            'original' => 'array',
            'files' => 'array',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * Every requested change: text fields and replacement documents (store_logo, business_permit, …).
     *
     * @return array<int, string>
     */
    public function changedFields(): array
    {
        // getAttribute() is required: inside the model `$this->changes` is Eloquent's own
        // dirty-tracking property, not the `changes` column.
        return array_merge(
            array_keys($this->getAttribute('changes') ?? []),
            array_keys($this->getAttribute('files') ?? []),
        );
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === MerchantChangeRequestStatus::Pending;
    }
}
