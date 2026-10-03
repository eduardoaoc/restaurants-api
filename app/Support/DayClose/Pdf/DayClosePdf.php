<?php

namespace App\Support\DayClose\Pdf;

use App\Models\RestaurantDayClose;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Renders the PDF of a persisted Cierre Diario (CARTA 9.1C), on demand —
 * no storage, no cache: the snapshot is small, generation takes well
 * under a second, and nothing has to be invalidated when an annotation is
 * added.
 *
 * TEMPLATE_VERSION versions the LAYOUT only. It is independent from
 * RestaurantDayClose::report_schema_version (the data contract) — a visual
 * change bumps this, never the snapshot schema.
 *
 * dompdf runs locked down: no PHP, no JavaScript, no remote resources
 * (the template is self-contained: inline CSS, the bundled DejaVu Sans
 * font — subset-embedded, so a close weighs tens of KB, not ~1 MB — no
 * CDN/Google Fonts), chroot to the project.
 */
final class DayClosePdf
{
    public const TEMPLATE_VERSION = 1;

    public const VIEW = 'pdf.day-close';

    public function html(RestaurantDayClose $dayClose): string
    {
        $dayClose->loadMissing('annotations');

        return view(self::VIEW, (new DayClosePdfPresenter($dayClose))->toViewData())->render();
    }

    public function render(RestaurantDayClose $dayClose): string
    {
        $pdf = Pdf::loadHTML($this->html($dayClose))
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'isFontSubsettingEnabled' => true,
                'chroot' => base_path(),
            ]);

        // "Página N / M": dompdf's CSS counter(pages) isn't resolved in a
        // fixed footer, so the total is stamped on the canvas after layout
        // — from PHP here, never from inline PHP in the template.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 45.35 - 62, $canvas->get_height() - 40.5, 'Página {PAGE_NUM} / {PAGE_COUNT}', $font, 7.5, [0.4, 0.4, 0.4]);

        return $dompdf->output();
    }

    /**
     * "aforo-cierre-diario-aforo-ruzafa-2026-10-02.pdf": the restaurant
     * name from the snapshot, slugged (ASCII, [a-z0-9-], max 40 chars) —
     * never raw — plus the business date. No internal ids.
     */
    public function filename(RestaurantDayClose $dayClose): string
    {
        $slug = Str::limit(Str::slug((string) ($dayClose->report['summary']['restaurant']['name'] ?? '')), 40, '');
        $slug = trim($slug, '-');

        return 'aforo-cierre-diario-'.($slug !== '' ? $slug.'-' : '').$dayClose->business_date->format('Y-m-d').'.pdf';
    }
}
