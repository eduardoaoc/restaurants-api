<?php

namespace Tests\Feature\Table;

use App\Actions\Tables\OpenTableAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_lists_tables(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/tables")
            ->assertOk();

        $ids = collect($response->json('data.tables'))->pluck('id');
        $this->assertTrue($ids->contains($table->id));
    }

    public function test_cashier_without_manage_tables_can_still_view_tables(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/tables")
            ->assertOk();
    }

    public function test_kitchen_cannot_view_tables(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/tables")
            ->assertForbidden();
    }

    public function test_index_reports_active_session_summary(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        app(OpenTableAction::class)->execute($table, $owner, 4);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/tables")
            ->assertOk();

        $data = collect($response->json('data.tables'))->firstWhere('id', $table->id);

        $this->assertTrue($data['has_active_session']);
        $this->assertSame(4, $data['active_session']['guest_count']);
        $this->assertSame('occupied', $data['active_session']['status']);
    }
}
