<?php

namespace Tests\Feature\Table;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TablePublicTokenRotationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_owner_rotates_the_token_and_the_old_qr_stops_working(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $oldToken = $table->public_token;

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/public-token/rotate")
            ->assertOk();

        $newToken = $response->json('data.table.public_token');
        $this->assertIsString($newToken);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame($newToken, $table->fresh()->public_token);

        $this->getJson("/api/v1/public/tables/{$oldToken}")->assertNotFound();
        $this->getJson("/api/v1/public/tables/{$newToken}")->assertOk();
    }

    public function test_rotation_is_audited_without_the_token_values(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $oldToken = $table->public_token;

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/public-token/rotate")
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_PUBLIC_TOKEN_ROTATED)->sole();
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame(AuditLog::RESOURCE_TABLE, $log->resource_type);
        $this->assertSame($table->id, $log->resource_id);

        $serialized = json_encode($log->getAttributes());
        $this->assertStringNotContainsString($oldToken, $serialized);
        $this->assertStringNotContainsString($table->fresh()->public_token, $serialized);
    }

    public function test_staff_without_table_permissions_cannot_rotate(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/tables/{$table->id}/public-token/rotate")
            ->assertForbidden();

        $this->assertSame($table->public_token, $table->fresh()->public_token);
    }

    public function test_table_of_another_organization_is_not_found(): void
    {
        [, $owner] = $this->createTenant();
        [, , $otherRestaurant] = $this->createTenant();
        $foreignTable = $this->createTable($otherRestaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$foreignTable->id}/public-token/rotate")
            ->assertNotFound();

        $this->assertSame($foreignTable->public_token, $foreignTable->fresh()->public_token);
    }
}
