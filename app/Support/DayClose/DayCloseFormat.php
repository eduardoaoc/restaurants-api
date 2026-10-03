<?php

namespace App\Support\DayClose;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Value formatting for the Cierre Diario snapshot (CARTA 9.1A). The
 * report only ever holds strings, ints, bools and nulls — never floats —
 * so its canonical JSON (and therefore report_sha256) is byte-for-byte
 * reproducible: money is a decimal string ("1842.50"), instants are UTC
 * ISO-8601 with second precision ("2026-10-02T21:00:00Z").
 */
final class DayCloseFormat
{
    public static function instant(?DateTimeInterface $instant): ?string
    {
        if ($instant === null) {
            return null;
        }

        return ($instant instanceof CarbonInterface ? $instant->copy() : CarbonImmutable::instance($instant))
            ->utc()
            ->format('Y-m-d\TH:i:s\Z');
    }

    public static function money(int $cents): string
    {
        return Money::centsToDecimal($cents);
    }

    /**
     * Canonical JSON: associative arrays get their keys sorted
     * recursively, lists keep their order; unicode and slashes unescaped.
     *
     * @param  array<mixed>  $value
     */
    public static function canonicalJson(array $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $report
     */
    public static function hash(array $report): string
    {
        return hash('sha256', self::canonicalJson($report));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }

    /**
     * Free text (notes, reasons) stored and rendered as plain text, never
     * HTML: trimmed, control characters other than newline/tab removed,
     * blank becomes null. HTML-looking input is kept verbatim as text —
     * escaping is the renderer's job, never a lossy strip here.
     */
    public static function plainText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));

        return $clean === '' ? null : $clean;
    }
}
