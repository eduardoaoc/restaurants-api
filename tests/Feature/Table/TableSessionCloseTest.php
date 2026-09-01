<?php

namespace Tests\Feature\Table;

use App\Actions\Tables\OpenTableAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableSessionCloseTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_close_the_active_session(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'closed');
    }

    public function test_close_sets_closed_at_and_closed_by(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();

        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertNotNull($session->closed_at);
        $this->assertSame($owner->id, $session->closed_by_user_id);
    }

    public function test_cashier_can_close_an_active_session(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();
    }

    public function test_closing_a_table_without_an_active_session_returns_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409);
    }

    public function test_closing_a_table_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        app(OpenTableAction::class)->execute($tableB, $ownerB, 4);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/close")
            ->assertNotFound();
    }

    public function test_kitchen_cannot_close_a_table(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertForbidden();
    }
}
