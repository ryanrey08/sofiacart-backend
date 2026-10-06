<?php

namespace App\Enums;

enum MerchantChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    // Cancelled by the merchant before review.
    case Withdrawn = 'withdrawn';
}
