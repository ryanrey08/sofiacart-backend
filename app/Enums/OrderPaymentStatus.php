<?php

namespace App\Enums;

enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
