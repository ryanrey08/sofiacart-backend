<?php

namespace App\Enums;

enum TransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Payment = 'payment';
    case Refund = 'refund';
}
