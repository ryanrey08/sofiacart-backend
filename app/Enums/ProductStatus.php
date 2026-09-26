<?php

namespace App\Enums;

enum ProductStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Draft = 'draft';
    case Archived = 'archived';
    case Rejected = 'rejected';
}
