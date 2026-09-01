<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaffAction;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves that CreateStaffAction::execute() is wrapped in a real transaction:
 * if a link fails partway through, nothing survives — not even the User row
 * that was already inserted before the failure.
 */
class StaffTransactionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_no_user_is_persisted_when_the_restaurant_link_fails(): void
    {
        $organization = Organization::factory()->create();

        $otherOrganization = Organization::factory()->create();
        $restaurantFromOtherOrganization = Restaurant::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $action = app(CreateStaffAction::class);

        try {
            $action->execute($organization, [
                'name' => 'Broken Staff',
                'email' => 'broken-staff@example.com',
                'password' => 'password123',
                // Belongs to a different organization: the scoped lookup
                // inside the action will fail after the User row has
                // already been inserted and attached to organization_users.
                'restaurant_id' => $restaurantFromOtherOrganization->id,
                'role' => 'waiter',
                'sub_id' => 'W-1',
            ]);

            $this->fail('Expected the action to throw because the restaurant does not belong to the organization.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertDatabaseMissing('users', ['email' => 'broken-staff@example.com']);
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id]);
        $this->assertDatabaseMissing('restaurant_users', ['sub_id' => 'W-1']);
        $this->assertDatabaseMissing('user_roles', ['organization_id' => $organization->id]);
    }
}
