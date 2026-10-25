<?php

declare(strict_types=1);

namespace App\Domain;

enum CashMovementType: string
{
    case CashInSale = 'CASH_IN_SALE';
    case CashInOnline = 'CASH_IN_ONLINE';
    case CashInService = 'CASH_IN_SERVICE';
    case CashOutChange = 'CASH_OUT_CHANGE';
    case CashOutRefund = 'CASH_OUT_REFUND';
    case CashOutExpense = 'CASH_OUT_EXPENSE';
    case CashOutSupplier = 'CASH_OUT_SUPPLIER';
    case ShiftOpening = 'SHIFT_OPENING';
    case ShiftClosingDifference = 'SHIFT_CLOSING_DIFFERENCE';
}