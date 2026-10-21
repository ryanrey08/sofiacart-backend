<?php

namespace App\Enums;

enum ProductChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    // Cancelled by the merchant before review.
    case Withdrawn = 'withdrawn';
}
