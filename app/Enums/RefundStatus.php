<?php

namespace App\Enums;

/**
 * "pending" is a requested refund and "processed" a completed one; the values predate the
 * processing/failed/cancelled states and are kept for compatibility.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Refunds that still hold their amount and item quantities against the payment/order.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Approved, self::Processing, self::Failed];
    }

    /**
     * Statuses that count against refundable amounts and quantities.
     *
     * @return list<self>
     */
    public static function committed(): array
    {
        return [...self::open(), self::Processed];
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled, self::Processing, self::Processed],
            self::Approved => [self::Processing, self::Processed, self::Rejected, self::Cancelled],
            self::Processing => [self::Processed, self::Failed],
            self::Failed => [self::Processing, self::Processed, self::Cancelled],
            self::Rejected, self::Processed, self::Cancelled => [],
        };
    }
}
