<?php

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_changes_and_metadata_are_cast_to_arrays(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $log = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'actor_user_id' => $owner->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'event' => AuditLog::EVENT_STAFF_UPDATED,
            'resource_type' => AuditLog::RESOURCE_STAFF,
            'resource_id' => $owner->id,
            'changes' => ['name' => ['old' => 'A', 'new' => 'B']],
            'metadata' => ['foo' => 'bar'],
            'created_at' => now(),
        ]);

        $fresh = $log->fresh();
        $this->assertIsArray($fresh->changes);
        $this->assertIsArray($fresh->metadata);
        $this->assertEquals(['name' => ['old' => 'A', 'new' => 'B']], $fresh->changes);
    }

    public function test_actor_organization_and_restaurant_relations(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $log = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'actor_user_id' => $owner->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'event' => AuditLog::EVENT_STAFF_UPDATED,
            'resource_type' => AuditLog::RESOURCE_STAFF,
            'resource_id' => $owner->id,
            'created_at' => now(),
        ]);

        $this->assertTrue($log->actor->is($owner));
        $this->assertTrue($log->organization->is($organization));
        $this->assertTrue($log->restaurant->is($restaurant));
    }

    public function test_has_no_updated_at_column(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $log = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'actor_user_id' => $owner->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'event' => AuditLog::EVENT_STAFF_UPDATED,
            'resource_type' => AuditLog::RESOURCE_STAFF,
            'resource_id' => $owner->id,
            'created_at' => now(),
        ]);

        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
        $this->assertFalse($log->usesTimestamps());
    }
}
