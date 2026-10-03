{{--
    Cierre Diario PDF — template v{{ $template_version }} (CARTA 9.1C).
    Rendered by dompdf from DayClosePdfPresenter (persisted snapshot only).
    Every user-generated string goes through {{ }} / e(): never raw HTML.
    Self-contained: inline CSS, bundled DejaVu Sans, no remote assets.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>AFORO · Cierre diario {{ $business_date }}</title>
<style>
    @page { margin: 26mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f1f1f; line-height: 1.35; }

    #page-header { position: fixed; top: -18mm; left: 0; right: 0; height: 10mm; border-bottom: 0.6pt solid #c9c9c9; font-size: 8pt; color: #555; }
    #page-header .brand { font-weight: bold; letter-spacing: 1.5pt; color: #d9480f; }
    #page-footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 8mm; border-top: 0.6pt solid #c9c9c9; font-size: 7.5pt; color: #666; }

    h1 { font-size: 17pt; margin: 0 0 1mm 0; }
    h1 .brand { color: #d9480f; letter-spacing: 2pt; }
    h2 { font-size: 10.5pt; text-transform: uppercase; letter-spacing: 0.8pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 1.2pt solid #d9480f; page-break-after: avoid; }
    h3 { font-size: 9.5pt; margin: 3.5mm 0 1.5mm 0; page-break-after: avoid; }

    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 1.4mm 1.8mm; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .grid th { background: #f2f2f2; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; border-bottom: 0.8pt solid #999; }
    .grid td { border-bottom: 0.5pt solid #dedede; }
    .kv td { border-bottom: 0.5pt solid #e6e6e6; }
    .kv td.label { width: 58%; color: #444; }
    .kv td.value, .num { text-align: right; white-space: nowrap; }
    .strong td { font-weight: bold; }

    .meta td { padding: 0.8mm 0; }
    .meta td.label { width: 34%; color: #555; }
    .muted { color: #666; }
    .empty { color: #555; font-style: italic; margin: 1mm 0 2mm 0; }
    .note { border-left: 2pt solid #d9480f; background: #fafafa; padding: 2mm 3mm; margin: 1.5mm 0; }
    .comment { margin-top: 1mm; }
    .badge { display: inline-block; padding: 0.4mm 2mm; border: 0.8pt solid #d9480f; color: #d9480f; font-size: 7.5pt; font-weight: bold; }
    .later { border: 1pt dashed #777; padding: 3mm; margin-top: 2mm; }
    .later-head { font-size: 8pt; color: #444; margin-bottom: 2mm; }
    .section { page-break-inside: auto; }
    .products-summary { page-break-inside: avoid; }
</style>
</head>
<body>

<div id="page-header">
    <table><tr>
        <td><span class="brand">AFORO</span> · Cierre diario</td>
        <td class="num">{{ $restaurant_name }} · {{ $business_date }}</td>
    </tr></table>
</div>
<div id="page-footer">
    <table><tr>
        <td>Ref. {{ $reference }} · informe v{{ $report_schema_version }} · plantilla v{{ $template_version }}</td>
    </tr></table>
</div>

<h1><span class="brand">AFORO</span> · Cierre diario</h1>
<table class="meta">
    <tr><td class="label">Restaurante</td><td>{{ $restaurant_name }}</td></tr>
    <tr><td class="label">Fecha de negocio</td><td>{{ $business_date }}@if ($business_date_range) <span class="muted">(cubre {{ $business_date_range }})</span>@endif</td></tr>
    <tr><td class="label">Periodo</td><td>{{ $period }} <span class="muted">({{ $timezone }})</span></td></tr>
    <tr><td class="label">Responsable</td><td>{{ $closed_by }}</td></tr>
    <tr><td class="label">Fecha/hora de cierre</td><td>{{ $closed_at }}</td></tr>
</table>
@if ($first_close)
    <p class="muted">Primer cierre del restaurante: la actividad anterior al inicio del periodo no forma parte de este cierre.</p>
@endif

<h2>Resumen @if ($has_incidents)<span class="badge">CON INCIDENCIAS</span>@endif</h2>
<table class="kv">
    @foreach ($summary as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
    @endforeach
</table>
@if (count($warnings) > 0)
    <h3>Avisos registrados al cerrar</h3>
    <table class="kv">
        @foreach ($warnings as $warning)
            <tr><td>{{ $warning }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Financiero</h2>
<table class="kv">
    @foreach ($financial as [$label, $value])
        <tr class="{{ $loop->first ? 'strong' : '' }}"><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
    @endforeach
</table>
<p class="muted">Total cobrado: pagos realmente registrados en el periodo. No incluye gastos ni es un resultado o margen.</p>

<h2>Caja</h2>
<table class="kv">
    @foreach ($cash as [$label, $value])
        <tr class="{{ $label === 'Diferencia' ? 'strong' : '' }}"><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
    @endforeach
</table>
@if ($cash_difference_note !== null)
    <div class="note"><strong>Observación de la diferencia:</strong><div class="comment">{!! nl2br(e($cash_difference_note)) !!}</div></div>
@endif
@if (count($cash_movements) > 0)
    <h3>Entradas y retiradas de efectivo</h3>
    <table class="grid">
        <thead><tr><th>Tipo</th><th>Motivo</th><th>Registrado por</th><th>Hora</th><th class="num">Importe</th></tr></thead>
        <tbody>
        @foreach ($cash_movements as $movement)
            <tr><td>{{ $movement['type'] }}</td><td>{{ $movement['reason'] }}</td><td>{{ $movement['by'] }}</td><td>{{ $movement['at'] }}</td><td class="num">{{ $movement['amount'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Operación</h2>
<table class="kv">
    @foreach ($operations as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
    @endforeach
</table>

<div class="products-summary">
<h2>Productos</h2>
@if ($top_product)
    <p><strong>Producto más vendido:</strong> {{ $top_product['name'] }} ({{ $top_product['quantity'] }} uds.)</p>
    <table class="grid">
        <thead><tr><th>#</th><th>Producto</th><th class="num">Cantidad</th></tr></thead>
        <tbody>
        @foreach ($top_products as $product)
            <tr><td>{{ $loop->iteration }}</td><td>{{ $product['name'] }}</td><td class="num">{{ $product['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@else
    <p class="empty">No se vendieron productos durante el periodo.</p>
@endif

</div>

<h2>Productos marcados como no disponibles</h2>
@if (count($availability) > 0)
    <table class="grid">
        <thead><tr><th>Producto</th><th>Desde</th><th>Marcado por</th><th>Hasta</th><th class="num">Duración</th></tr></thead>
        <tbody>
        @foreach ($availability as $item)
            <tr>
                <td>{{ $item['product'] }}</td>
                <td>{{ $item['since'] }}</td>
                <td>{{ $item['by'] }}</td>
                <td>{{ $item['until'] }}@if ($item['until_by'])<br><span class="muted">por {{ $item['until_by'] }}</span>@endif</td>
                <td class="num">{{ $item['duration'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@else
    <p class="empty">Ningún producto fue marcado como no disponible durante el periodo.</p>
@endif

<h2>Feedback</h2>
<table class="kv">
    @foreach ($feedback_summary as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
    @endforeach
</table>

<h2>Feedback que requiere atención</h2>
@if (count($critical_feedback) > 0)
    @foreach ($critical_feedback as $item)
        <div class="note">
            <strong>{{ $item['table'] }}</strong> · {{ $item['at'] }}@if ($item['waiter']) · <span class="muted">atendida por {{ $item['waiter'] }}</span>@endif<br>
            {{ $item['ratings'] }}
            @if ($item['experience_comment'] !== null)<div class="comment">«{!! nl2br(e($item['experience_comment'])) !!}»</div>@endif
            @if ($item['improvement_comment'] !== null)<div class="comment"><span class="muted">A mejorar:</span> «{!! nl2br(e($item['improvement_comment'])) !!}»</div>@endif
        </div>
    @endforeach
@else
    <p class="empty">No hubo reseñas críticas.</p>
@endif
@if (count($attention_feedback) > 0)
    <h3>Con alguna dimensión baja (no críticas)@if ($attention_total > count($attention_feedback)) — {{ count($attention_feedback) }} de {{ $attention_total }}@endif</h3>
    <table class="grid">
        <thead><tr><th>Mesa</th><th>Hora</th><th>Puntuaciones</th></tr></thead>
        <tbody>
        @foreach ($attention_feedback as $item)
            <tr><td>{{ $item['table'] }}</td><td>{{ $item['at'] }}</td><td>{{ $item['ratings'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Incidencias de tiempo</h2>
@if (count($delays) > 0)
    <p class="muted">Límites: @foreach ($delays_thresholds as [$stage, $limit]){{ $stage }} {{ $limit }}@if (! $loop->last) · @endif @endforeach</p>
    <table class="grid">
        <thead><tr><th>Pedido</th><th>Mesa</th><th>Etapa</th><th class="num">Duración</th><th class="num">Límite</th><th class="num">Exceso</th><th>Acción asociada</th></tr></thead>
        <tbody>
        @foreach ($delays as $item)
            <tr><td>{{ $item['reference'] }}</td><td>{{ $item['table'] }}</td><td>{{ $item['stage'] }}</td><td class="num">{{ $item['duration'] }}</td><td class="num">{{ $item['threshold'] }}</td><td class="num">{{ $item['excess'] }}</td><td>{{ $item['action'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($delays_total > count($delays))
        <p class="muted">Se muestran las {{ count($delays) }} de mayor exceso, de {{ $delays_total }} incidencias en total.</p>
    @endif
@else
    <p class="empty">No se registraron incidencias de tiempo.</p>
@endif

<h2>Observaciones</h2>
@if ($notes !== null)
    <div class="note">{!! nl2br(e($notes)) !!}</div>
@else
    <p class="empty">Sin observaciones.</p>
@endif

<h2>Cierre</h2>
<table class="meta">
    <tr><td class="label">Responsable</td><td>{{ $closed_by }}</td></tr>
    <tr><td class="label">Fecha/hora de cierre</td><td>{{ $closed_at }}</td></tr>
    <tr><td class="label">Fecha de negocio</td><td>{{ $business_date }}</td></tr>
    <tr><td class="label">Periodo</td><td>{{ $period }} <span class="muted">({{ $timezone }})</span></td></tr>
    <tr><td class="label">Referencia</td><td>{{ $reference }}</td></tr>
</table>

@if (count($annotations) > 0)
    <h2>Notas posteriores</h2>
    <div class="later">
        <div class="later-head">Añadidas DESPUÉS del cierre. No modifican ninguno de los valores anteriores, que corresponden al cierre original.</div>
        @foreach ($annotations as $annotation)
            <div class="note">
                <span class="muted">{{ $annotation['at'] }} · {{ $annotation['by'] }}</span>
                <div class="comment">{!! nl2br(e($annotation['body'])) !!}</div>
            </div>
        @endforeach
    </div>
@endif

</body>
</html>
