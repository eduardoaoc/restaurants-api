<?php

namespace App\Support\WhatsApp;

use App\Models\RestaurantDayClose;
use App\Support\DayClose\DayCloseLocaleFormat;

/**
 * Persisted Cierre Diario -> the NAMED body parameters of the approved
 * WhatsApp template (CARTA 9.1E). Same rule as the PDF: only the
 * RestaurantDayClose columns and its `report` snapshot — never live
 * orders/payments/feedback/analytics. No customer data, no comments, no
 * staff list: just the short summary + the authenticated report link.
 *
 * Every value is single-line plain text (newlines/tabs become spaces,
 * runs of spaces collapse, max 200 chars): template parameters are
 * rendered inline by WhatsApp, and Meta rejects malformed parameter text
 * (error 132018). Never an empty string or a literal "null".
 */
final class DayCloseWhatsAppPresenter
{
    /**
     * Template contract (body variables, in order). Changing it requires a
     * new template approved in WhatsApp Manager.
     *
     * @var array<int, string>
     */
    public const PARAMETERS = [
        'restaurant', 'business_date',
        'total', 'cash', 'card', 'other', 'cash_difference',
        'orders', 'guests', 'average_ticket', 'top_product',
        'critical_reviews', 'delays', 'unavailable_products',
        'closed_by', 'report_url',
    ];

    private const MAX_LENGTH = 200;

    public function __construct(
        private readonly RestaurantDayClose $dayClose,
        private readonly WhatsAppConfig $config,
    ) {}

    /**
     * @return array<string, string> parameter_name => text, in PARAMETERS order
     */
    public function parameters(): array
    {
        $dc = $this->dayClose;
        $format = new DayCloseLocaleFormat($dc->currency);
        $top = $dc->report['summary']['top_product'] ?? null;

        $values = [
            'restaurant' => (string) ($dc->report['summary']['restaurant']['name'] ?? ''),
            'business_date' => DayCloseLocaleFormat::date($dc->business_date->format('Y-m-d')),
            'total' => $format->money($dc->total_received),
            'cash' => $format->money($dc->cash_received),
            'card' => $format->money($dc->card_received),
            'other' => $format->money($dc->other_received),
            'cash_difference' => $format->signedMoney($dc->cash_difference),
            'orders' => (string) $dc->orders_valid,
            'guests' => (string) $dc->guests,
            'average_ticket' => $format->money($dc->average_ticket),
            'top_product' => $top !== null ? $top['name'].' ('.$top['quantity'].')' : 'Sin ventas registradas',
            'critical_reviews' => (string) $dc->critical_feedback_count,
            'delays' => (string) $dc->delays_count,
            'unavailable_products' => (string) $dc->unavailable_products_count,
            'closed_by' => $dc->closed_by_name_snapshot,
            'report_url' => $this->config->reportUrl($dc->id),
        ];

        return array_map(fn (string $value) => $this->clean($value), $values);
    }

    private function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? '—' : mb_substr($value, 0, self::MAX_LENGTH);
    }
}
