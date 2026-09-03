<?php

namespace Tests\Feature\Public;

use App\Actions\Tables\OpenTableAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicTableResolutionTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_resolves_a_valid_token_without_authentication(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant, 'Mesa 12', 12);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk();

        $response->assertJson([
            'data' => [
                'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->name],
                'table' => ['id' => $table->id, 'name' => 'Mesa 12', 'number' => 12],
                'session' => ['active' => false, 'status' => null],
                'menu' => ['available' => false],
            ],
        ]);
    }

    public function test_content_type_is_json(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_response_does_not_depend_on_tenant_context(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        // No auth, no session cookie, no tenant header of any kind.
        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk();

        $this->assertSame($restaurant->id, $response->json('data.restaurant.id'));
    }

    public function test_response_never_exposes_internal_identifiers(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 2);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk();

        $json = json_encode($response->json());

        $this->assertStringNotContainsString('organization_id', $json);
        $this->assertStringNotContainsString('public_token', $json);
        $this->assertStringNotContainsString($table->public_token, $json);
        $this->assertStringNotContainsString('opened_by_user_id', $json);
        $this->assertStringNotContainsString('closed_by_user_id', $json);
        $this->assertStringNotContainsString('session.id', $json);

        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['active', 'status'], array_keys($data['session']));
    }

    public function test_unknown_token_returns_neutral_404(): void
    {
        $response = $this->getJson('/api/v1/public/tables/does-not-exist')
            ->assertStatus(404);

        $response->assertJson([
            'error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.'],
        ]);
    }

    public function test_inactive_table_returns_neutral_404(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $table->update(['status' => 'inactive']);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']]);
    }

    public function test_blocked_table_returns_neutral_404(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $table->update(['status' => 'blocked']);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']]);
    }

    public function test_inactive_restaurant_returns_neutral_404(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $restaurant->update(['status' => 'inactive']);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']]);
    }

    public function test_all_neutral_failure_scenarios_share_identical_body(): void
    {
        [, , $restaurant] = $this->createTenant();

        $inactiveTable = $this->createTable($restaurant);
        $inactiveTable->update(['status' => 'inactive']);

        $blockedTable = $this->createTable($restaurant);
        $blockedTable->update(['status' => 'blocked']);

        $inactiveRestaurant = $this->createTable($restaurant);
        $restaurant->update(['status' => 'inactive']);

        $expected = ['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']];

        $this->getJson('/api/v1/public/tables/unknown-token')->assertStatus(404)->assertExactJson($expected);
        $this->getJson("/api/v1/public/tables/{$inactiveTable->public_token}")->assertStatus(404)->assertExactJson($expected);
        $this->getJson("/api/v1/public/tables/{$blockedTable->public_token}")->assertStatus(404)->assertExactJson($expected);
        $this->getJson("/api/v1/public/tables/{$inactiveRestaurant->public_token}")->assertStatus(404)->assertExactJson($expected);
    }

    public function test_active_session_is_reported(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['active' => true, 'status' => 'occupied']]]);
    }

    public function test_no_session_is_reported_as_inactive_with_null_status(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['active' => false, 'status' => null]]]);
    }

    public function test_closed_session_is_reported_as_inactive_with_null_status(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 4);
        $this->closeSessionWithFullPayment($session, $owner);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['session' => ['active' => false, 'status' => null]]]);
    }

    public function test_qr_remains_valid_after_session_is_closed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 4);
        $this->closeSessionWithFullPayment($session, $owner);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk();
    }

    public function test_menu_available_true_when_menu_is_active(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['menu' => ['available' => true]]]);
    }

    public function test_menu_available_false_when_no_menu_exists(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['menu' => ['available' => false]]]);
    }

    public function test_menu_available_false_when_menu_is_inactive(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $menu->update(['status' => 'inactive']);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJson(['data' => ['menu' => ['available' => false]]]);
    }

    public function test_response_shape_contains_exactly_the_expected_top_level_keys(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();

        $this->assertEqualsCanonicalizing(
            ['restaurant', 'table', 'session', 'menu'],
            array_keys($response->json('data'))
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'default_locale', 'enabled_locales', 'capabilities'],
            array_keys($response->json('data.restaurant'))
        );
        $this->assertEqualsCanonicalizing(['id', 'name', 'number'], array_keys($response->json('data.table')));
        $this->assertEqualsCanonicalizing(['active', 'status'], array_keys($response->json('data.session')));
        $this->assertEqualsCanonicalizing(['available'], array_keys($response->json('data.menu')));
    }
}
