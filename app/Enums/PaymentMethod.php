<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::MobileMoney => 'Mobile Money',
            self::BankTransfer => 'Virement',
            self::Cheque => 'Chèque',
        };
    }

    /**
     * Whether a transaction or cheque number should be noted.
     */
    public function needsReference(): bool
    {
        return $this !== self::Cash;
    }
}
