<?php

namespace Tests\Feature\Security;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Feedback\CustomerFeedbackSummaryResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * SEC-006: a null organization-wide restaurant scope must never mean
 * all organizations when passed to a feedback aggregate query.
 */
class FeedbackSummaryTenantBoundaryTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    public static function membershipStates(): array
    {
        return ['inactive membership' => ['inactive'], 'removed membership' => ['removed']];
    }

    #[DataProvider('membershipStates')]
    public function test_organization_wide_role_does_not_expose_feedback_from_a_revoked_foreign_tenant(string $membershipState): void
    {
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        [$organizationA, $actor, $a1] = $this->createTenant();
        [$organizationB, $ownerB, $b1] = $this->createTenant();
        // Legitimate historical waiter service in B, followed by revocation.
        $this->assignRole($actor, 'waiter', $organizationB, $b1);
        $b1->users()->attach($actor->id, ['sub_id' => 'historical-waiter']);
        $tableB = $this->createTable($b1);
        $sessionB = $this->openSession($tableB, $ownerB);
        app(AssignWaiterAction::class)->execute($sessionB, $actor, $ownerB);
        $this->createServedOrder($tableB, $ownerB);
        $this->recordPayment($sessionB, $ownerB, '10.00');
        $this->createFeedback($sessionB->fresh(), ['overall' => 1]);

        if ($membershipState === 'inactive') {
            $organizationB->users()->updateExistingPivot($actor->id, ['status' => OrganizationUser::STATUS_INACTIVE]);
        } else {
            $organizationB->users()->detach($actor->id);
            $b1->users()->detach($actor->id);
            $actor->userRoles()->where('organization_id', $organizationB->id)->delete();
        }

        $this->as($actor)->getJson("/api/v1/restaurants/{$b1->id}/feedback")->assertNotFound();
        $response = $this->getJson('/api/v1/me/feedback-summary')->assertOk();
        // Organization A has no feedback; B is outside the active tenant
        // and no longer authorized. B's aggregate must not be returned.
        $response->assertJsonPath('data.feedback_count', 0);
        $response->assertJsonPath('data.average_overall', null);
        $response->assertJsonPath('data.average_service', null);
        $response->assertJsonPath('data.average_wait_time', null);
        $response->assertJsonPath('data.average_food', null);
    }

    private function historicalFeedback(Restaurant $restaurant, User $owner, User $waiter, int $rating): void
    {
        if (! $waiter->userRoles()->where('restaurant_id', $restaurant->id)->exists()) {
            $this->assignRole($waiter, 'waiter', $restaurant->organization, $restaurant);
        }
        $restaurant->users()->syncWithoutDetaching([$waiter->id => ['sub_id' => 'waiter-'.$restaurant->id]]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $this->createServedOrder($table, $owner);
        $this->recordPayment($session, $owner, '10.00');
        $this->createFeedback($session->fresh(), ['overall' => $rating]);
    }

    public static function activeTenants(): array
    {
        return ['tenant A' => [false], 'tenant B' => [true]];
    }

    #[DataProvider('activeTenants')]
    public function test_active_memberships_in_both_organizations_do_not_combine_feedback(bool $selectB): void
    {
        $this->seedRolesAndPermissions();
        // ResolveTenant selects the first active membership. Create the intended
        // tenant first, preserving two active memberships throughout the request.
        $first = $this->createTenant();
        $second = $this->createTenant();
        [$orgA, $ownerA, $a1] = $selectB ? $second : $first;
        [$orgB, $ownerB, $b1] = $selectB ? $first : $second;
        $actor = $first[1];
        $this->assignRole($actor, 'owner', $second[0]);
        $this->historicalFeedback($a1, $ownerA, $actor, 5);
        $this->historicalFeedback($b1, $ownerB, $actor, 1);
        $this->as($actor)->getJson('/api/v1/me/feedback-summary')->assertOk()
            ->assertExactJson(['data' => [
                'feedback_count' => 1,
                'average_overall' => $selectB ? 1 : 5,
                'average_service' => $selectB ? 1 : 5,
                'average_wait_time' => $selectB ? 1 : 5,
                'average_food' => $selectB ? 1 : 5,
            ]]);
        $this->assertSame(($selectB ? $orgB : $orgA)->id, app(TenantContext::class)->getOrganizationId());
        $this->assertSame(2, $actor->organizations()->wherePivot('status', OrganizationUser::STATUS_ACTIVE)->count());
        $this->assertDatabaseCount('customer_feedbacks', 2);
    }

    public function test_organization_wide_owner_aggregates_both_restaurants_in_active_organization(): void
    {
        $this->seedRolesAndPermissions();
        [$org, $actor, $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->historicalFeedback($a1, $actor, $actor, 5);
        $this->historicalFeedback($a2, $actor, $actor, 1);
        $this->as($actor)->getJson('/api/v1/me/feedback-summary')->assertOk()
            ->assertJson(['data' => ['feedback_count' => 2, 'average_overall' => 3,
                'average_service' => 3, 'average_wait_time' => 3, 'average_food' => 3]]);
        $this->assertDatabaseCount('customer_feedbacks', 2);
    }

    public function test_restaurant_scoped_waiter_excludes_historical_feedback_after_sibling_access_removal(): void
    {
        $this->seedRolesAndPermissions();
        [$org, $owner, $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $org->id]);
        $actor = $this->createStaff($org, $a1, 'waiter', 'scoped');
        $this->historicalFeedback($a1, $owner, $actor, 5);
        $this->historicalFeedback($a2, $owner, $actor, 1);
        $a2->users()->detach($actor->id);
        $actor->userRoles()->where('restaurant_id', $a2->id)->delete();
        $this->as($actor)->getJson('/api/v1/me/feedback-summary')->assertOk()
            ->assertJson(['data' => ['feedback_count' => 1, 'average_overall' => 5,
                'average_service' => 5, 'average_wait_time' => 5, 'average_food' => 5]]);
        $this->assertDatabaseCount('customer_feedbacks', 2);
    }

    public function test_resolver_empty_set_and_foreign_ids_cannot_remove_organization_scope(): void
    {
        $this->seedRolesAndPermissions();
        [$orgA, $actor] = $this->createTenant();
        [, $ownerB, $b1] = $this->createTenant();
        $this->historicalFeedback($b1, $ownerB, $actor, 1);
        foreach ([[], [$b1->id]] as $ids) {
            $summary = CustomerFeedbackSummaryResolver::forWaiter($actor->id, $orgA->id, $ids);
            $this->assertSame(0, $summary->feedbackCount);
            $this->assertNull($summary->averageOverall);
            $this->assertNull($summary->averageService);
            $this->assertNull($summary->averageWaitTime);
            $this->assertNull($summary->averageFood);
        }
        $this->assertSame(1, CustomerFeedbackSummaryResolver::forWaiter($actor->id, $b1->organization_id, [$b1->id])->feedbackCount);
    }
}
