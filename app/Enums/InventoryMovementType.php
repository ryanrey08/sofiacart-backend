<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case Cancellation = 'cancellation';
    case Return = 'return';
}
