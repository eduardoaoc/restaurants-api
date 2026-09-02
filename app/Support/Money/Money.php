<?php

namespace App\Support\Money;

/**
 * Converts between decimal money strings ("12.90") and integer cents (1290).
 *
 * All order pricing math (see BuildOrderItemsAction/OrderCreationService)
 * is done in integer cents and only converted back to a decimal string at
 * the point of persistence/display. This avoids binary float error
 * entirely (e.g. 0.10 + 0.20 !== 0.30 in IEEE 754) — no float ever appears
 * in the calculation path, only integers and strings.
 */
class Money
{
    /**
     * Parse a "12.9"/"12.90"/"-3.5" decimal string into integer cents.
     * Purely string/integer based — never goes through float arithmetic.
     */
    public static function decimalToCents(string $decimal): int
    {
        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');

        if ($negative) {
            $decimal = substr($decimal, 1);
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    /**
     * Format integer cents back into a "12.90" decimal string.
     */
    public static function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;

        return ($negative ? '-' : '').sprintf('%d.%02d', $whole, $fraction);
    }
}
