<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processed = 'processed';
}
