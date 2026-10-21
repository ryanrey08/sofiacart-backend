<?php

namespace App\Models;

use App\Enums\ProductChangeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's edit to an already-approved product. The live product keeps its approved values
 * until an admin approves the request (see ProductApprovalService).
 */
class ProductChangeRequest extends Model
{
    protected $fillable = [
        'product_id',
        'merchant_id',
        'status',
        'changes',
        'original',
        'images',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductChangeRequestStatus::class,
            'changes' => 'array',
            'original' => 'array',
            'images' => 'array',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * Every requested change, including a replacement gallery ("images").
     *
     * @return array<int, string>
     */
    public function changedFields(): array
    {
        // getAttribute() is required: inside the model `$this->changes` is Eloquent's own
        // dirty-tracking property, not the `changes` column.
        $fields = array_keys($this->getAttribute('changes') ?? []);

        if ($this->getAttribute('images')) {
            $fields[] = 'images';
        }

        return array_values(array_unique($fields));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
        return $this->status === ProductChangeRequestStatus::Pending;
    }
}
