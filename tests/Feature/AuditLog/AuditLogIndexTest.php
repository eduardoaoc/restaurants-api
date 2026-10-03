<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Writes an AuditLog row directly (bypassing AuditLogger, which is
     * fine here — these tests exercise the read endpoint's scoping,
     * filtering and pagination, not the write path already covered
     * elsewhere).
     */
    private function makeLog(
        Organization $organization,
        ?Restaurant $restaurant,
        ?User $actor,
        string $event = AuditLog::EVENT_ORDER_SERVED,
        string $resourceType = AuditLog::RESOURCE_ORDER,
        ?int $resourceId = 1,
        ?CarbonImmutable $createdAt = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant?->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor ? AuditLog::ACTOR_USER : AuditLog::ACTOR_PUBLIC,
            'event' => $event,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => ['previous_status' => 'ready', 'new_status' => 'served'],
            'created_at' => $createdAt ?? CarbonImmutable::now(),
        ]);
    }

    public function test_owner_sees_logs_of_every_restaurant_of_the_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->makeLog($organization, $restaurantA, $owner);
        $this->makeLog($organization, $restaurantB, $owner);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertCount(2, $response->json('data.audit_logs'));
    }

    public function test_manager_sees_only_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $this->makeLog($organization, $restaurantA, $manager);
        $this->makeLog($organization, $restaurantB, $manager);

        $response = $this->actingAs($manager, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $logs = $response->json('data.audit_logs');
        $this->assertCount(1, $logs);
        $this->assertSame($restaurantA->id, $logs[0]['restaurant']['id']);
    }

    public function test_missing_view_audit_permission_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_restaurant_filter_in_scope_succeeds_and_out_of_scope_is_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($manager, 'web')->getJson("/api/v1/audit-logs?restaurant_id={$restaurantA->id}")->assertOk();
        $this->actingAs($manager, 'web')->getJson("/api/v1/audit-logs?restaurant_id={$restaurantB->id}")->assertNotFound();
    }

    public function test_owner_restaurant_filter_works_for_both_restaurants(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/audit-logs?restaurant_id={$restaurantA->id}")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/audit-logs?restaurant_id={$restaurantB->id}")->assertOk();
    }

    public function test_filters_event_resource_type_resource_id_and_actor(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->makeLog($organization, $restaurant, $owner, AuditLog::EVENT_ORDER_SERVED, AuditLog::RESOURCE_ORDER, 100);
        $this->makeLog($organization, $restaurant, $waiter, AuditLog::EVENT_ORDER_CREATED, AuditLog::RESOURCE_ORDER, 200);
        $this->makeLog($organization, $restaurant, $owner, AuditLog::EVENT_TABLE_SESSION_OPENED, AuditLog::RESOURCE_TABLE_SESSION, 300);

        $byEvent = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?event=order.served')->assertOk();
        $this->assertCount(1, $byEvent->json('data.audit_logs'));

        $byResourceType = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?resource_type=table_session')->assertOk();
        $this->assertCount(1, $byResourceType->json('data.audit_logs'));

        $byResourceId = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?resource_id=200')->assertOk();
        $this->assertCount(1, $byResourceId->json('data.audit_logs'));

        $byActor = $this->actingAs($owner, 'web')->getJson("/api/v1/audit-logs?actor_user_id={$waiter->id}")->assertOk();
        $this->assertCount(1, $byActor->json('data.audit_logs'));
    }

    public function test_invalid_event_and_resource_type_filters_are_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?event=not.a.real.event')->assertStatus(422);
        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?resource_type=not_a_type')->assertStatus(422);
    }

    public function test_date_range_filter(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->makeLog($organization, $restaurant, $owner, createdAt: CarbonImmutable::parse('2026-01-15'));
        $this->makeLog($organization, $restaurant, $owner, createdAt: CarbonImmutable::parse('2026-03-15'));

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/audit-logs?from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->assertCount(1, $response->json('data.audit_logs'));
    }

    public function test_period_validation(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?from=2026-01-01')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AUDIT_PERIOD');
        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?to=2026-01-31')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AUDIT_PERIOD');
        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?from=2026-01-31&to=2026-01-01')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AUDIT_PERIOD');
        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?from=2025-01-01&to=2026-01-02')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_AUDIT_PERIOD');
    }

    public function test_no_period_returns_most_recent_entries_without_implicit_filtering(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->makeLog($organization, $restaurant, $owner, createdAt: CarbonImmutable::parse('2020-01-01'));
        $this->makeLog($organization, $restaurant, $owner, createdAt: CarbonImmutable::now());

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertCount(2, $response->json('data.audit_logs'));
    }

    public function test_pagination_default_custom_and_max(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        for ($i = 0; $i < 30; $i++) {
            $this->makeLog($organization, $restaurant, $owner, resourceId: $i);
        }

        $default = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertCount(25, $default->json('data.audit_logs'));
        $this->assertSame(25, $default->json('meta.per_page'));
        $this->assertSame(30, $default->json('meta.total'));

        $custom = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?per_page=10')->assertOk();
        $this->assertCount(10, $custom->json('data.audit_logs'));
        $this->assertSame(3, $custom->json('meta.last_page'));

        $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs?per_page=101')->assertStatus(422);
    }

    public function test_ordering_is_newest_first(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->travelTo(CarbonImmutable::now()->subMinutes(10));
        $oldest = $this->makeLog($organization, $restaurant, $owner);
        $this->travelTo(CarbonImmutable::now()->addMinutes(5));
        $middle = $this->makeLog($organization, $restaurant, $owner);
        $this->travelTo(CarbonImmutable::now()->addMinutes(5));
        $newest = $this->makeLog($organization, $restaurant, $owner);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $ids = $response->json('data.audit_logs.*.id');
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_resource_contract_excludes_sensitive_and_internal_fields(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->makeLog($organization, $restaurant, $owner);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('organization_id', $raw);
        $this->assertStringNotContainsString($owner->email, $raw);
        $this->assertStringNotContainsString('password', $raw);
    }

    public function test_tenant_isolation_organization_b_never_appears(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB, $ownerB, $restaurantB] = $this->createTenant();

        $this->makeLog($organizationA, $restaurantA, $ownerA);
        $this->makeLog($organizationB, $restaurantB, $ownerB);

        $response = $this->actingAs($ownerA, 'web')->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertCount(1, $response->json('data.audit_logs'));

        // Filtering by organization B's own actor must not leak it either.
        $viaActorFilter = $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/audit-logs?actor_user_id={$ownerB->id}")
            ->assertOk();
        $this->assertCount(0, $viaActorFilter->json('data.audit_logs'));
    }

    public function test_restaurant_isolation_manager_never_sees_other_restaurant_even_via_filters(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->makeLog($organization, $restaurantB, $staffB);

        $response = $this->actingAs($manager, 'web')
            ->getJson("/api/v1/audit-logs?actor_user_id={$staffB->id}")
            ->assertOk();

        $this->assertCount(0, $response->json('data.audit_logs'));
    }
}
