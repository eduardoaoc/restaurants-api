<?php

namespace Tests\Feature\DayClose;

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\DayClose\Pdf\DayClosePdf;
use App\Support\DayClose\Pdf\DayClosePdfPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1C — GET /day-closes/{id}/pdf: generated from the persisted
 * snapshot only.
 *
 * Content assertions run on the HTML the PDF is rendered from
 * (DayClosePdf::html) — the PDF streams themselves are compressed — plus
 * the real HTTP response for status/headers/magic bytes.
 */
class DayClosePdfTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: Restaurant, 1: User, 2: RestaurantDayClose}
     */
    private function closedDay(array $overrides = []): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->update(['name' => 'AFORO Ruzafa']);
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '1842.50', 'card', productName: 'Paella');
        $this->servedPaidSession($restaurant, $owner, '25.00', 'cash', productName: 'Agua');
        $this->at('2026-10-02 21:00:00');
        $id = $this->closeDay($restaurant, $owner, ['counted_cash' => '123.00', ...$overrides])->assertCreated()->json('data.id');

        return [$restaurant->refresh(), $owner, RestaurantDayClose::query()->findOrFail($id)];
    }

    private function html(RestaurantDayClose $dayClose): string
    {
        return app(DayClosePdf::class)->html($dayClose->fresh());
    }

    public function test_owner_and_manager_download_the_pdf_with_safe_headers(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedDay();
        $manager = $this->createStaff($restaurant->organization, $restaurant, 'manager', 'M-1');

        foreach ([$owner, $manager] as $user) {
            $response = $this->as($user)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertSame('attachment; filename="aforo-cierre-diario-aforo-ruzafa-2026-10-02.pdf"', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    public function test_roles_without_view_daily_closes_get_403(): void
    {
        [$restaurant, , $dayClose] = $this->closedDay();

        foreach (['waiter', 'cashier', 'kitchen'] as $i => $role) {
            $user = $this->createStaff($restaurant->organization, $restaurant, $role, 'S-'.$i);
            $this->as($user)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertForbidden();
        }
    }

    public function test_tenant_isolation_returns_404(): void
    {
        [$restaurant, , $dayClose] = $this->closedDay();
        $sibling = Restaurant::factory()->create(['organization_id' => $restaurant->organization_id]);
        $siblingManager = $this->createStaff($restaurant->organization, $sibling, 'manager', 'MB-1');
        [, $otherOwner] = $this->createTenant();

        $this->as($siblingManager)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertNotFound();
        $this->as($otherOwner)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertNotFound();
        $this->get('/api/v1/day-closes/999999/pdf')->assertNotFound();
    }

    public function test_unauthenticated_gets_401(): void
    {
        [, , $dayClose] = $this->closedDay();
        Auth::forgetGuards();
        $this->flushSession();

        $this->getJson("/api/v1/day-closes/{$dayClose->id}/pdf")->assertUnauthorized();
    }

    public function test_content_comes_from_the_snapshot_not_current_data(): void
    {
        [$restaurant, $owner, $dayClose] = $this->closedDay();
        $before = $this->html($dayClose);

        $this->assertStringContainsString('1.867,50 €', $before); // total cobrado
        $this->assertStringContainsString('1.842,50 €', $before); // tarjeta
        $this->assertStringContainsString('125,00 €', $before);   // esperado = 100 + 25
        $this->assertStringContainsString('123,00 €', $before);   // contado
        $this->assertStringContainsString('−2,00 €', $before);    // diferencia
        $this->assertStringContainsString('AFORO Ruzafa', $before);

        // Later operational changes: renamed restaurant/products, new
        // payments, different timezone/thresholds.
        $this->at('2026-10-03 12:00:00');
        $restaurant->update(['name' => 'Renamed Restaurant']);
        $restaurant->settings()->update(['timezone' => 'Asia/Tokyo', 'preparation_delay_threshold_minutes' => 1]);
        DB::table('products')->update(['internal_name' => 'Renamed product']);
        $this->servedPaidSession($restaurant, $owner, '999.00', 'cash');

        $after = $this->html($dayClose);
        $this->assertSame($before, $after);
        $this->assertStringNotContainsString('Renamed', $after);
        $this->assertStringNotContainsString('999,00', $after);

        // The PDF itself still renders, from the same snapshot.
        $this->assertStringStartsWith('%PDF-', $this->as($owner)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertOk()->getContent());
    }

    public function test_pdf_generation_never_queries_operational_tables(): void
    {
        [, $owner, $dayClose] = $this->closedDay();
        $tables = [];
        DB::listen(function ($query) use (&$tables) {
            preg_match_all('/(?:from|join)\s+"([a-z_]+)"/i', $query->sql, $matches);
            $tables = [...$tables, ...$matches[1]];
        });

        $this->as($owner)->get("/api/v1/day-closes/{$dayClose->id}/pdf")->assertOk();

        foreach (['orders', 'order_items', 'payment_records', 'table_sessions', 'customer_feedbacks', 'restaurant_products', 'products', 'restaurant_activity_events', 'restaurant_cash_movements'] as $operational) {
            $this->assertNotContains($operational, $tables, "PDF must not read {$operational}");
        }
        $this->assertContains('restaurant_day_closes', $tables);
        $this->assertContains('restaurant_day_close_annotations', $tables);
    }

    public function test_annotations_only_appear_in_the_separate_later_notes_section(): void
    {
        [, $owner, $dayClose] = $this->closedDay();
        $before = $this->html($dayClose);
        $this->assertStringNotContainsString('Notas posteriores', $before);
        $hash = $dayClose->report_sha256;

        $this->at('2026-10-04 10:15:00');
        $this->as($owner)->postJson("/api/v1/day-closes/{$dayClose->id}/annotations", ['body' => "Cobro duplicado devuelto.\nRevisado con el banco."])->assertCreated();

        $after = $this->html($dayClose);
        $this->assertSame($this->bodyWithoutLaterNotes($before), $this->bodyWithoutLaterNotes($after));
        $this->assertStringContainsString('Añadidas DESPUÉS del cierre', $after);
        $this->assertStringContainsString('Cobro duplicado devuelto.<br />', $after);
        $this->assertStringContainsString('04/10/2026 12:15', $after); // Madrid time of the annotation
        $this->assertSame($hash, $dayClose->fresh()->report_sha256);
    }

    private function bodyWithoutLaterNotes(string $html): string
    {
        $cut = strpos($html, '<h2>Notas posteriores</h2>');

        return $cut === false ? trim(explode('</body>', $html)[0]) : trim(substr($html, 0, $cut));
    }

    public function test_user_generated_text_is_escaped(): void
    {
        [, $owner, $dayClose] = $this->closedDay(['notes' => '<script>alert(1)</script> & <b>bold</b>']);
        $this->as($owner)->postJson("/api/v1/day-closes/{$dayClose->id}/annotations", ['body' => '<img src=x onerror=alert(2)>'])->assertCreated();

        $html = $this->html($dayClose);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
    }

    public function test_empty_sections_render_short_lines(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');
        $id = $this->closeDay($restaurant, $owner)->assertCreated()->json('data.id');

        $html = $this->html(RestaurantDayClose::query()->findOrFail($id));

        foreach ([
            'No hubo reseñas críticas.',
            'Ningún producto fue marcado como no disponible durante el periodo.',
            'No se registraron incidencias de tiempo.',
            'No se vendieron productos durante el periodo.',
            'Sin observaciones.',
        ] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        $this->assertStringNotContainsString('Notas posteriores', $html);
        $this->assertStringNotContainsString('CON INCIDENCIAS', $html);
    }

    public function test_critical_feedback_without_customer_pii(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');
        $session = $this->servedPaidSession($restaurant, $owner);
        $this->createFeedback($session, ['overall' => 1, 'food' => 2, 'service' => 1, 'wait_time' => 3], 'Comida fría');
        $this->at('2026-10-02 21:00:00');
        $id = $this->closeDay($restaurant, $owner)->assertCreated()->json('data.id');

        $html = $this->html(RestaurantDayClose::query()->findOrFail($id));

        $this->assertStringContainsString('General 1 · Comida 2 · Servicio 1 · Espera 3', $html);
        $this->assertStringContainsString('Comida fría', $html);
        $this->assertStringContainsString('02/10/2026 20:00', $html); // submitted 18:00Z, Madrid
        foreach (['Private-Surname', 'ana.private@example.com', 'Ana', 'first_name', 'last_name', 'contact'] as $pii) {
            $this->assertStringNotContainsString($pii, $html, $pii);
        }
    }

    public function test_timestamps_use_the_persisted_timezone_of_the_close(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'America/New_York', 'default_opening_float' => '0.00']);
        $this->at('2026-10-02 23:30:00'); // 19:30 New York
        $id = $this->closeDay($restaurant, $owner)->assertCreated()->json('data.id');
        $restaurant->settings()->update(['timezone' => 'Asia/Tokyo']);

        $html = $this->html(RestaurantDayClose::query()->findOrFail($id));

        $this->assertStringContainsString('02/10/2026 19:30', $html);
        $this->assertStringContainsString('America/New_York', $html);
        $this->assertStringNotContainsString('Asia/Tokyo', $html);
    }

    public function test_money_uses_the_persisted_currency_without_floats(): void
    {
        $eur = new DayClosePdfPresenter(new RestaurantDayClose(['currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'report' => []]));
        $usd = new DayClosePdfPresenter(new RestaurantDayClose(['currency' => 'USD', 'timezone' => 'Europe/Madrid', 'report' => []]));

        $this->assertSame('1.842,50 €', $eur->money('1842.50'));
        $this->assertSame('1.234.567,89 €', $eur->money('1234567.89'));
        $this->assertSame('0,10 €', $eur->money('0.10'));
        $this->assertSame('−5,01 €', $eur->money('-5.01'));
        $this->assertSame('+3,00 €', $eur->signedMoney('3.00'));
        $this->assertSame('1.842,50 USD', $usd->money('1842.50'));
        $this->assertSame('1 h 32 min', $eur->duration(5520));
        $this->assertSame('25 min', $eur->duration(1500));
    }

    public function test_long_content_renders_without_losing_rows(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $paella = $this->createRestaurantProduct($restaurant, $this->createProduct($organization, 'Paella', [['locale' => 'en', 'name' => 'Paella valenciana con un nombre de producto bastante largo para probar el ajuste']]));
        $transition = app(TransitionOrderStatusAction::class);

        $this->at('2026-10-02 12:00:00');
        foreach (range(1, 8) as $i) {
            $product = $this->createRestaurantProduct($restaurant, $this->createProduct($organization, "Producto no disponible {$i}"));
            app(UpdateRestaurantProductAction::class)->execute($product, $owner, ['available' => false]);
        }
        $table = $this->createTable($restaurant, 'Terraza 14', 14);
        $session = $this->openSession($table, $owner);
        $orders = collect(range(1, 24))->map(fn () => $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $paella->id, 'quantity' => 1]]));
        $orders->each(function (Order $order, int $i) use ($transition, $owner) {
            $this->at('2026-10-02 12:'.(15 + $i).':00');
            $transition->serve($transition->markReady($transition->startPreparing($transition->accept($order, $owner), $owner), $owner), $owner);
        });
        $this->at('2026-10-02 13:00:00');
        $this->closeSessionWithFullPayment($session->refresh(), $owner);
        foreach (range(1, 6) as $i) {
            $this->createFeedback($this->servedPaidSession($restaurant, $owner), ['overall' => 1], str_repeat("Comentario largo {$i}. ", 40));
        }
        $this->at('2026-10-02 21:00:00');
        $id = $this->closeDay($restaurant, $owner, ['notes' => str_repeat('Nota muy larga del turno. ', 76)])->assertCreated()->json('data.id');
        $this->as($owner)->postJson("/api/v1/day-closes/{$id}/annotations", ['body' => str_repeat('Anotación posterior extensa. ', 68)])->assertCreated();

        $dayClose = RestaurantDayClose::query()->findOrFail($id);
        $html = $this->html($dayClose);

        $this->assertSame(24, $dayClose->delays_count);
        // 20 detail rows + the stage name once in the "Límites:" line.
        $this->assertSame(21, substr_count($html, 'Espera para aceptar'));
        $this->assertStringContainsString('Se muestran las 20 de mayor exceso, de 24 incidencias en total.', $html);
        $this->assertSame(6, substr_count($html, 'General 1 · Comida 1'));
        $this->assertSame(8, substr_count($html, 'Producto no disponible'));

        $pdf = app(DayClosePdf::class)->render($dayClose);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (getenv('DAY_CLOSE_PDF_DUMP')) {
            file_put_contents(getenv('DAY_CLOSE_PDF_DUMP'), $pdf);
        }
    }
}
