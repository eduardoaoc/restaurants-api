<?php

namespace App\Support\DayClose;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * es-ES display formatting shared by every Cierre Diario rendering — the
 * PDF (CARTA 9.1C) and the WhatsApp message (CARTA 9.1E). Money is
 * formatted from the persisted decimal string through integer cents
 * (never a float, never converted), in the close's own persisted
 * currency: "1.842,50 €" for EUR, "1.842,50 USD" otherwise.
 */
final class DayCloseLocaleFormat
{
    public function __construct(private readonly string $currency) {}

    public function money(string|int|null $decimal): string
    {
        if ($decimal === null) {
            return '—';
        }

        $cents = Money::decimalToCents((string) $decimal);
        $negative = $cents < 0;
        $cents = abs($cents);
        $amount = ($negative ? '−' : '').number_format(intdiv($cents, 100), 0, ',', '.').','.sprintf('%02d', $cents % 100);

        return $this->currency === 'EUR' ? $amount.' €' : $amount.' '.$this->currency;
    }

    public function signedMoney(string $decimal): string
    {
        return Money::decimalToCents($decimal) > 0 ? '+'.$this->money($decimal) : $this->money($decimal);
    }

    public static function date(string $ymd): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd)->format('d/m/Y');
    }
}
