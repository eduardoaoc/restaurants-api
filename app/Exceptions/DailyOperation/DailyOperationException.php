<?php

namespace App\Exceptions\DailyOperation;

use RuntimeException;

/**
 * Domain refusals of the operational day, rendered exactly like
 * DayCloseException: {"error": {"code", "message", ...details}} with its
 * own HTTP status (bootstrap/app.php).
 */
class DailyOperationException extends RuntimeException
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

    /** No open operational day: new sessions, orders and public requests are refused. */
    public static function notOpen(): self
    {
        return new self('RESTAURANT_DAY_NOT_OPEN', 409, 'The restaurant has not opened its operational day yet.');
    }

    /**
     * @param  array<string, mixed>  $operation  non-financial state only
     */
    public static function alreadyOpen(array $operation): self
    {
        return new self('DAILY_OPERATION_ALREADY_OPEN', 409, 'The operational day is already open.', ['daily_operation' => $operation]);
    }

    public static function businessDateAlreadyClosed(string $businessDate): self
    {
        return new self('BUSINESS_DATE_ALREADY_CLOSED', 409, 'This business date is already closed. Open the next day after the business-day cutoff.', ['business_date' => $businessDate]);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 409, 'This idempotency key was already used with a different request.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseError(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage(), ...$this->details];
    }
}
