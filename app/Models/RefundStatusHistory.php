<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['from_status', 'to_status', 'user_id', 'notes', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_status' => RefundStatus::class,
            'to_status' => RefundStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
