<?php

namespace Tests\Feature\TableRequest;

use App\Models\Restaurant;
use App\Models\TableRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 8.2A — GET /table-requests?table_id=.
 */
class TableRequestTableFilterTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Sanctum's request guard caches the first authenticated user for the
     * whole test, and AuthenticateSession pins the session to the previous
     * user's password hash — forget both so each request really runs as
     * $user.
     */
    private function as(User $user): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    /**
     * @return array<int, int>
     */
    private function ids(TestResponse $response): array
    {
        return collect($response->json('data.table_requests'))->pluck('id')->all();
    }

    public function test_without_table_id_lists_every_table_as_before(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);
        $requestB = $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $response = $this->as($owner)->getJson('/api/v1/table-requests')->assertOk();

        $this->assertSame([$requestA->id, $requestB->id], $this->ids($response));
    }

    public function test_table_id_returns_only_that_tables_requests(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);
        $requestA = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $response = $this->as($waiter)
            ->getJson("/api/v1/table-requests?restaurant_id={$restaurant->id}&table_id={$tableA->id}")
            ->assertOk();
        $this->assertSame([$requestA->id], $this->ids($response));

        $response = $this->as($waiter)
            ->getJson("/api/v1/table-requests?table_id={$tableA->id}&type=call_waiter&status=pending")
            ->assertOk();
        $this->assertSame([$requestA->id], $this->ids($response));
    }

    public function test_table_id_of_another_restaurant_than_restaurant_id_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->as($owner)
            ->getJson("/api/v1/table-requests?restaurant_id={$restaurantA->id}&table_id={$tableB->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id')
            ->assertJsonMissingPath('data');
    }

    public function test_table_of_a_restaurant_outside_the_users_scope_is_indistinguishable_from_unknown(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $foreign = $this->as($waiterA)->getJson("/api/v1/table-requests?table_id={$tableB->id}")->assertUnprocessable();
        $unknown = $this->as($waiterA)->getJson('/api/v1/table-requests?table_id=999999')->assertUnprocessable();

        $this->assertSame($unknown->json(), $foreign->json());
    }

    public function test_table_of_another_organization_is_rejected(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $this->createTableRequest($tableB, TableRequest::TYPE_CALL_WAITER);

        $this->as($ownerA)
            ->getJson("/api/v1/table-requests?table_id={$tableB->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id');
    }

    public function test_table_id_must_be_an_integer(): void
    {
        [, $owner] = $this->createTenant();

        $this->as($owner)
            ->getJson('/api/v1/table-requests?table_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id');
    }
}
