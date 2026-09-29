<?php

namespace App\Enums;

enum MerchantStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case InformationRequested = 'information_requested';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
}
