<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Statuses whose amount was actually collected (refunds are tracked separately).
     *
     * @return list<self>
     */
    public static function captured(): array
    {
        return [self::Completed, self::PartiallyRefunded, self::Refunded];
    }

    public function isCaptured(): bool
    {
        return in_array($this, self::captured(), true);
    }
}
