<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case Gcash = 'gcash';
    case Maya = 'maya';
    case BankTransfer = 'bank_transfer';
    case Cod = 'cod';
    case Cash = 'cash';
    case Paypal = 'paypal';
    case Other = 'other';

    /**
     * Map a legacy free-text `gateway` value (e.g. "paymaya") to a method.
     */
    public static function fromLegacyGateway(?string $gateway): ?self
    {
        return match (strtolower((string) $gateway)) {
            'card', 'credit_card' => self::Card,
            'gcash' => self::Gcash,
            'maya', 'paymaya' => self::Maya,
            'bank_transfer' => self::BankTransfer,
            'cod', 'cash_on_delivery' => self::Cod,
            'cash' => self::Cash,
            'paypal' => self::Paypal,
            default => null,
        };
    }
}
