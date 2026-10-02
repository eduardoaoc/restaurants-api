<?php

namespace App\Exceptions\Tables;

use RuntimeException;

/**
 * Raised when voiding a table session that is not empty (CARTA 9.1A —
 * VoidEmptyTableSessionAction): it has a payment, a billable order or an
 * order still in progress. Such a session must go through the normal
 * serve/pay/close flow instead. Rendered as 409 TABLE_SESSION_NOT_EMPTY
 * with the concrete `reason` — see bootstrap/app.php.
 */
class TableSessionNotEmptyException extends RuntimeException
{
    public const REASON_HAS_PAYMENTS = 'has_payments';

    public const REASON_HAS_BILLABLE_ORDERS = 'has_billable_orders';

    public const REASON_HAS_OPEN_ORDERS = 'has_open_orders';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('This table session is not empty and cannot be voided.');
    }
}
