<?php

namespace App\Enums;

enum CustomerType: string
{
    case Regular = 'regular';
    case Vip = 'vip';
    case Wholesale = 'wholesale';
}
