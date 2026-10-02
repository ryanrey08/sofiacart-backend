<?php

namespace App\Enums;

enum InventoryAdjustmentType: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Set = 'set';

    public function movementType(): InventoryMovementType
    {
        return match ($this) {
            self::Increase => InventoryMovementType::StockIn,
            self::Decrease => InventoryMovementType::StockOut,
            self::Set => InventoryMovementType::Adjustment,
        };
    }

    public function defaultReason(): string
    {
        return match ($this) {
            self::Increase => 'Stock added',
            self::Decrease => 'Stock deducted',
            self::Set => 'Stock count set',
        };
    }
}
