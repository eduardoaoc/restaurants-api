<?php

namespace Tests\Feature\Security;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\RestaurantDayClose;
use App\Support\DayClose\Pdf\DayClosePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

class StoredTextRenderingTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    public static function payloads(): array
    {
        return array_map(fn ($payload) => [$payload], ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>']);
    }

    #[DataProvider('payloads')]
    public function test_persisted_names_comments_and_notes_are_data_in_json_and_escaped_in_pdf(string $payload): void
    {
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        Http::preventStrayRequests();
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['default_locale' => 'en', 'enabled_locales' => ['en']]);
        $this->as($owner)->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => $payload])->assertOk();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'text');
        $this->as($owner)->patchJson("/api/v1/staff/{$staff->id}", ['name' => $payload])->assertOk();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, translations: [['locale' => 'en', 'name' => $payload, 'description' => $payload]]);
        $product = $this->createProduct($organization, $payload, [['locale' => 'en', 'name' => $payload, 'description' => $payload]]);
        $rp = $this->createRestaurantProduct($restaurant, $product);
        $category->categoryProducts()->create(['restaurant_product_id' => $rp->id]);
        $group = $this->createModifierGroup($rp, $payload, translations: [['locale' => 'en', 'name' => $payload, 'description' => $payload]]);
        $option = $this->createModifierOption($group, $payload, translations: [['locale' => 'en', 'name' => $payload, 'description' => $payload]]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $menuResponse = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=en")->assertOk();
        $this->assertStringStartsWith('application/json', $menuResponse->headers->get('Content-Type'));
        $values = [];
        $data = $menuResponse->json();
        array_walk_recursive($data, function ($value) use (&$values) {
            $values[] = $value;
        });
        $this->assertContains($payload, $values);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]]], ['locale' => 'en']);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, '10.00');
        app(CloseTableAction::class)->execute($session->refresh(), $owner);
        $this->createFeedback($session, ['overall' => 1], $payload);
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->as($owner)->postJson("/api/v1/restaurants/{$restaurant->id}/cash-movements", ['type' => 'pay_in', 'amount' => '1.00', 'reason' => $payload], ['Idempotency-Key' => 'text'])->assertCreated();
        $this->travel(2)->seconds();
        $id = $this->closeDay($restaurant, $owner, ['notes' => $payload, 'counted_cash' => '0.00', 'cash_difference_note' => $payload])->assertCreated()->json('data.id');
        $this->as($owner)->postJson("/api/v1/day-closes/{$id}/annotations", ['body' => $payload])->assertCreated();
        $html = app(DayClosePdf::class)->html(RestaurantDayClose::query()->findOrFail($id));
        foreach (['<script', '<img', '<svg'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $this->assertStringContainsString(e($payload), $html);
        $filename = app(DayClosePdf::class)->filename(RestaurantDayClose::query()->findOrFail($id));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $filename);
        $this->configureWhatsApp();
        $settings = $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => $payload, 'phone' => '+34612345678', 'consent_confirmed' => true])->assertOk();
        $this->assertStringNotContainsString('+34612345678', $settings->getContent());
        Http::assertNothingSent();
    }
}
