<?php

namespace App\Enums;

enum MerchantStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
