<?php

namespace App\Exceptions\DayClose;

use RuntimeException;

/**
 * Every domain refusal of the Cierre Diario (CARTA 9.1A), rendered as
 * {"error": {"code", "message", ...details}} with its own HTTP status —
 * one exception type with named constructors instead of one class per
 * code, since they all share the exact same rendering (bootstrap/app.php).
 */
class DayCloseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blockers
     */
    public static function blocked(array $blockers): self
    {
        return new self('CLOSE_BLOCKED', 422, 'The business day cannot be closed while the operation is still running.', ['blockers' => $blockers]);
    }

    public static function periodChanged(string $currentPeriodStartedAt): self
    {
        return new self('PERIOD_CHANGED', 409, 'The period to close changed since the preview (another close was completed).', ['current_period_started_at' => $currentPeriodStartedAt]);
    }

    public static function cashExpectationChanged(string $currentExpectedCash): self
    {
        return new self('CASH_EXPECTATION_CHANGED', 409, 'The expected cash changed since the preview. Review the cash count again.', ['current_expected_cash' => $currentExpectedCash]);
    }

    public static function businessDateAlreadyClosed(string $businessDate): self
    {
        return new self('BUSINESS_DATE_ALREADY_CLOSED', 409, 'This business date is already closed.', ['business_date' => $businessDate]);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 409, 'This idempotency key was already used with a different request.');
    }

    public static function emptyPeriod(): self
    {
        return new self('PERIOD_EMPTY', 409, 'The current period has not started yet.');
    }

    public static function expectedCashNegative(string $expectedCash): self
    {
        return new self('EXPECTED_CASH_NEGATIVE', 422, 'Cash pay-outs exceed the cash available in the drawer. Register the missing pay-in (or correct the opening float) before closing.', ['expected_cash' => $expectedCash]);
    }

    public static function openingFloatMismatch(string $suggestedOpeningFloat): self
    {
        return new self('OPENING_FLOAT_MISMATCH', 422, 'The opening float is inherited and cannot be changed at close; count the drawer and let the difference show it.', ['suggested_opening_float' => $suggestedOpeningFloat]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseError(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage(), ...$this->details];
    }
}
