<?php

namespace App\Support\DayClose\Pdf;

use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseAnnotation;
use App\Support\DayClose\DayCloseLocaleFormat;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Turns a PERSISTED Cierre Diario into display-ready strings for the PDF
 * (CARTA 9.1C). Its only inputs are the RestaurantDayClose columns, its
 * immutable `report` snapshot and its append-only annotations — never
 * orders, payments, products, sessions or analytics, so a PDF of an old
 * close always shows exactly what was closed.
 *
 * Presentation rules (es-ES — the only template locale of this version):
 *   - money: the persisted decimal string, never a float, grouped
 *     "1.842,50 €" (EUR) or "1.842,50 USD" for any other persisted code;
 *     never converted;
 *   - instants: converted to the close's OWN persisted timezone (not the
 *     restaurant's current one);
 *   - user-generated text (notes, comments, annotations) is returned raw
 *     and escaped by the Blade template — never rendered as HTML.
 */
final class DayClosePdfPresenter
{
    private const STAGE_LABELS = [
        'accept' => 'Espera para aceptar',
        'preparation' => 'Preparación',
        'ready_pickup' => 'Listo esperando servicio',
    ];

    private const ACTION_LABELS = [
        'marked_ready_by' => 'Marcado listo por',
        'served_by' => 'Servido por',
    ];

    private const WARNING_LABELS = [
        'open_table_requests_without_active_session' => 'Solicitudes de mesa abiertas sin sesión activa',
        'active_staff_shifts' => 'Turnos de personal aún abiertos',
        'products_still_unavailable' => 'Productos que siguen marcados como no disponibles',
        'critical_feedback' => 'Reseñas críticas',
        'severe_delays' => 'Incidencias de tiempo',
        'cash_difference' => 'Diferencia de caja',
    ];

    private readonly string $timezone;

    private readonly string $currency;

    private readonly DayCloseLocaleFormat $format;

    /** @var array<string, mixed> */
    private readonly array $report;

    public function __construct(private readonly RestaurantDayClose $dayClose)
    {
        $this->timezone = $dayClose->timezone;
        $this->currency = $dayClose->currency;
        $this->report = $dayClose->report;
        $this->format = new DayCloseLocaleFormat($this->currency);
    }

    /**
     * Everything the Blade template renders. Pure function of the
     * persisted close + annotations (no clock, no live data).
     *
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        $dc = $this->dayClose;
        $report = $this->report;
        $operations = $report['operations'];
        $feedback = $report['feedback'];
        $delays = $report['delays'];
        $cash = $report['cash'];

        return [
            'template_version' => DayClosePdf::TEMPLATE_VERSION,
            'reference' => $dc->public_id,
            'report_schema_version' => $dc->report_schema_version,
            'restaurant_name' => $report['summary']['restaurant']['name'],
            'business_date' => $this->date($dc->business_date->format('Y-m-d')),
            'business_date_range' => $dc->business_date_from->format('Y-m-d') !== $dc->business_date->format('Y-m-d')
                ? $this->date($dc->business_date_from->format('Y-m-d')).' – '.$this->date($dc->business_date->format('Y-m-d'))
                : null,
            'period' => $this->instant($dc->period_started_at).' → '.$this->instant($dc->period_ended_at),
            'timezone' => $this->timezone,
            'closed_by' => $dc->closed_by_name_snapshot,
            'closed_at' => $this->instant($dc->closed_at),
            'first_close' => (bool) ($report['summary']['first_close'] ?? false),
            'has_incidents' => $dc->has_incidents,

            'summary' => [
                ['Total cobrado', $this->money($dc->total_received)],
                ['Pedidos realizados', (string) $dc->orders_valid],
                ['Comensales', (string) $dc->guests],
                ['Ticket medio', $this->money($dc->average_ticket)],
                ['Diferencia de caja', $this->signedMoney($dc->cash_difference)],
                ['Incidencias', $dc->has_incidents ? 'Sí' : 'No'],
            ],

            'financial' => [
                ['Total cobrado', $this->money($dc->total_received)],
                ['Efectivo', $this->money($dc->cash_received)],
                ['Tarjeta', $this->money($dc->card_received)],
                ['Otros', $this->money($dc->other_received)],
                ['Ticket medio', $this->money($dc->average_ticket)],
                ['Cantidad de pagos', (string) $dc->payments_count],
                ['Mesas con pagos', (string) $dc->sessions_with_payments],
            ],

            'cash' => [
                ['Fondo inicial', $this->money($dc->opening_float).$this->openingFloatSource($cash['opening_float_source'] ?? null)],
                ['Entradas', $this->money($dc->cash_pay_ins)],
                ['Retiradas', $this->money($dc->cash_pay_outs)],
                ['Efectivo recibido', $this->money($dc->cash_received)],
                ['Efectivo esperado', $this->money($dc->expected_cash)],
                ['Efectivo contado', $this->money($dc->counted_cash)],
                ['Diferencia', $this->signedMoney($dc->cash_difference)],
                ['Efectivo que queda para el siguiente día', $dc->cash_left_for_next_day !== null ? $this->money($dc->cash_left_for_next_day) : '—'],
            ],
            'cash_difference_note' => $dc->cash_difference_note,
            'cash_movements' => array_map(fn (array $movement) => [
                'type' => $movement['type'] === 'pay_in' ? 'Entrada' : 'Retirada',
                'amount' => $this->money($movement['amount']),
                'reason' => $movement['reason'],
                'by' => $movement['recorded_by']['name'] ?? '—',
                'at' => $this->instant($movement['recorded_at']),
            ], $cash['movements'] ?? []),

            'operations' => [
                ['Pedidos realizados', (string) $dc->orders_valid],
                ['Pedidos registrados (cualquier estado)', (string) $dc->orders_registered],
                ['Pedidos servidos', (string) $dc->orders_served],
                ['Pedidos rechazados', (string) $dc->orders_rejected],
                ['Mesas abiertas', (string) $dc->sessions_opened],
                ['Mesas cerradas', (string) $dc->sessions_closed],
                ['Mesas anuladas (sin servicio)', (string) ($operations['sessions']['voided'] ?? 0)],
                ['Comensales', (string) $dc->guests],
                ['Hora punta', $this->peakHour($operations['peak_hour'] ?? null)],
            ],

            'top_product' => $report['summary']['top_product'] ?? null,
            'top_products' => $report['products']['top'] ?? [],

            'availability' => array_map(fn (array $item) => [
                'product' => $item['product_name_snapshot'] ?? '—',
                'since' => $item['unavailable_since_known'] ? $this->instant($item['unavailable_at']) : 'Inicio desconocido',
                'by' => $item['unavailable_by_name'] ?? '—',
                'until' => $item['state_at_close'] === 'unavailable' ? 'Sigue no disponible al cierre' : $this->instant($item['available_again_at']),
                'until_by' => $item['available_again_by_name'] ?? null,
                'duration' => $item['duration_seconds'] !== null ? $this->duration($item['duration_seconds']) : '—',
            ], $report['product_availability']['items'] ?? []),

            'feedback_summary' => [
                ['Reseñas recibidas', (string) $dc->feedback_count],
                ['Media general', $dc->feedback_avg_overall !== null ? $this->decimal($dc->feedback_avg_overall).' / 5' : '—'],
                ['Reseñas críticas (general < 3)', (string) $dc->critical_feedback_count],
                ['Con alguna dimensión baja (atención)', (string) $dc->low_dimension_feedback_count],
            ],
            'critical_feedback' => array_map(fn (array $item) => $this->feedbackItem($item), $feedback['critical'] ?? []),
            'attention_feedback' => array_map(fn (array $item) => $this->feedbackItem($item), $feedback['attention'] ?? []),
            'attention_total' => $dc->low_dimension_feedback_count,

            'delays_thresholds' => collect($delays['thresholds_seconds'] ?? [])
                ->map(fn (int $seconds, string $stage) => [self::STAGE_LABELS[$stage] ?? $stage, $this->duration($seconds)])
                ->values()->all(),
            'delays_total' => $dc->delays_count,
            'delays' => array_map(fn (array $item) => [
                'reference' => $item['order_reference'],
                'table' => $this->table($item['table'] ?? null),
                'stage' => self::STAGE_LABELS[$item['stage']] ?? $item['stage'],
                'duration' => $this->duration($item['duration_seconds']),
                'threshold' => $this->duration($item['threshold_seconds']),
                'excess' => '+'.$this->duration($item['excess_seconds']),
                'action' => $item['associated_action_user'] !== null && $item['associated_action_label'] !== null
                    ? (self::ACTION_LABELS[$item['associated_action_label']] ?? $item['associated_action_label']).' '.$item['associated_action_user']['name']
                    : '—',
            ], $delays['items'] ?? []),

            'warnings' => array_map(fn (array $warning) => $this->warning($warning), $report['warnings'] ?? []),
            'notes' => $dc->notes,

            'annotations' => $dc->annotations->map(fn (RestaurantDayCloseAnnotation $annotation) => [
                'body' => $annotation->body,
                'by' => $annotation->created_by_name_snapshot,
                'at' => $this->instant($annotation->created_at),
            ])->values()->all(),
        ];
    }

    public function money(string|int|null $decimal): string
    {
        return $this->format->money($decimal);
    }

    public function signedMoney(string $decimal): string
    {
        return $this->format->signedMoney($decimal);
    }

    /**
     * An instant (UTC ISO string or Carbon) in the close's persisted
     * timezone: "02/10/2026 21:47".
     */
    public function instant(mixed $instant): string
    {
        if ($instant === null) {
            return '—';
        }

        $utc = $instant instanceof DateTimeInterface ? CarbonImmutable::instance($instant) : CarbonImmutable::parse($instant, 'UTC');

        return $utc->setTimezone($this->timezone)
            ->format('d/m/Y H:i');
    }

    public function date(string $ymd): string
    {
        return DayCloseLocaleFormat::date($ymd);
    }

    public function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours} h {$minutes} min" : "{$hours} h";
        }

        return $minutes > 0 ? "{$minutes} min" : ($seconds % 60).' s';
    }

    private function decimal(string $value): string
    {
        return str_replace('.', ',', $value);
    }

    private function openingFloatSource(?string $source): string
    {
        return match ($source) {
            'previous_close' => ' (heredado del cierre anterior)',
            'restaurant_default' => ' (valor por defecto del restaurante)',
            'required' => ' (indicado al cerrar)',
            default => '',
        };
    }

    /**
     * @param  array{local_hour: string, sessions_started: int}|null  $peak
     */
    private function peakHour(?array $peak): string
    {
        if ($peak === null) {
            return '—';
        }

        // local_hour is already a local calendar hour ("2026-10-02T21:00").
        $local = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $peak['local_hour']);

        return $local->format('d/m/Y H:i').'–'.$local->addHour()->format('H:i').' ('.$peak['sessions_started'].' mesas abiertas)';
    }

    /**
     * @param  array{id: int, name: ?string, number: ?int}|null  $table
     */
    private function table(?array $table): string
    {
        if ($table === null) {
            return '—';
        }

        return $table['name'] ?? ('Mesa '.$table['number']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function feedbackItem(array $item): array
    {
        return [
            'table' => $this->table($item['table'] ?? null),
            'at' => $this->instant($item['submitted_at']),
            'ratings' => sprintf('General %d · Comida %d · Servicio %d · Espera %d', $item['ratings']['overall'], $item['ratings']['food'], $item['ratings']['service'], $item['ratings']['wait_time']),
            'experience_comment' => $item['experience_comment'],
            'improvement_comment' => $item['improvement_comment'],
            'waiter' => $item['waiter_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $warning
     */
    private function warning(array $warning): string
    {
        $label = self::WARNING_LABELS[$warning['type']] ?? $warning['type'];

        return match (true) {
            isset($warning['amount']) => $label.': '.$this->signedMoney($warning['amount']),
            isset($warning['count']) => $label.': '.$warning['count'],
            default => $label,
        };
    }
}
