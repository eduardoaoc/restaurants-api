<?php

namespace Tests\Feature\Security;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantResourceMatrixTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        Http::preventStrayRequests();
    }

    public static function scopes(): array
    {
        return ['A1 to A2' => [false], 'A1 to B1' => [true]];
    }

    #[DataProvider('scopes')]
    public function test_every_resource_family_rejects_swapped_ids(bool $crossOrganization): void
    {
        [$organization, , $a1] = $this->createTenant();
        $actor = $this->createStaff($organization, $a1, 'manager', 'actor');
        [$otherOrganization, $otherOwner, $b1] = $this->createTenant();
        $target = $crossOrganization ? $b1 : Restaurant::factory()->create(['organization_id' => $organization->id]);
        $targetOwner = $crossOrganization ? $otherOwner : $this->createStaff($organization, $target, 'manager', 'target-owner');
        $targetOrg = $target->organization;
        $floor = $this->createFloor($target);
        $zone = $this->createZone($floor);
        $menu = $this->createMenu($target);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($targetOrg);
        $rp = $this->createRestaurantProduct($target, $product);
        $group = $this->createModifierGroup($rp);
        $option = $this->createModifierOption($group);
        $category->categoryProducts()->create(['restaurant_product_id' => $rp->id]);
        $staff = $this->createStaff($targetOrg, $target, 'waiter', 'target');
        $shift = $this->startShift($target, $staff, $targetOwner);
        $this->endShift($shift, $targetOwner);
        $session = $this->servedPaidSession($target, $targetOwner);
        $order = $session->orders()->firstOrFail();
        $feedback = $this->createFeedback($session, ['overall' => 1]);
        $this->setDefaultOpeningFloat($target, '0.00');
        $this->travel(2)->seconds();
        $closeId = $this->closeDay($target, $targetOwner)->assertCreated()->json('data.id');
        $this->as($actor);

        $urls = [
            "/floors/{$floor->id}", "/zones/{$zone->id}", "/restaurants/{$target->id}/floor-plan",
            "/restaurants/{$target->id}/staff/{$staff->id}/feedback-summary",
            "/restaurants/{$target->id}", "/restaurants/{$target->id}/settings", "/restaurants/{$target->id}/tables",
            "/restaurants/{$target->id}/staff-shifts", "/restaurants/{$target->id}/feedback", "/restaurants/{$target->id}/activity",
            "/restaurants/{$target->id}/day-closes", "/restaurants/{$target->id}/cash-movements", "/restaurants/{$target->id}/day-close-whatsapp-settings",
            "/restaurants/{$target->id}/staff/{$staff->id}/performance", "/restaurants/{$target->id}/staff/{$staff->id}/reviews",
            "/tables/{$session->table_id}", "/tables/resolve/{$session->table->public_token}",
            "/table-sessions/{$session->id}/bill", "/table-sessions/{$session->id}/receipt", "/orders/{$order->id}",
            "/orders/{$order->id}/kitchen-ticket", "/staff/{$staff->id}", "/feedback/{$feedback->id}",
            "/day-closes/{$closeId}", "/day-closes/{$closeId}/pdf", "/day-closes/{$closeId}/deliveries",
            "/restaurants/{$target->id}/menu", "/restaurants/{$target->id}/categories", "/restaurants/{$target->id}/products",
            "/categories/{$category->id}", "/categories/{$category->id}/products", "/modifier-groups/{$group->id}",
            "/modifier-groups/{$group->id}/options", "/modifier-options/{$option->id}",
        ];
        if ($crossOrganization) {
            $urls[] = "/products/{$product->id}";
        }
        foreach ($urls as $url) {
            $response = $this->getJson('/api/v1'.$url);
            $this->assertContains($response->status(), [403, 404], $url.' returned '.$response->status());
        }
        $this->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '1.00'])->assertNotFound();
        $this->postJson("/api/v1/day-closes/{$closeId}/deliveries", [], ['Idempotency-Key' => 'swapped'])->assertNotFound();
        $this->postJson("/api/v1/day-closes/{$closeId}/annotations", ['body' => 'swapped'])->assertNotFound();
        $this->postJson("/api/v1/staff-shifts/{$shift->id}/end")->assertNotFound();
        $this->patchJson("/api/v1/restaurant-products/{$rp->id}", ['price' => '0.01'])->assertStatus($crossOrganization ? 404 : 403);
        $this->deleteJson("/api/v1/products/{$product->id}/media/image")->assertStatus($crossOrganization ? 404 : 403);
        $this->patchJson("/api/v1/floors/{$floor->id}", ['name' => 'swapped'])->assertNotFound();
        $this->patchJson("/api/v1/zones/{$zone->id}", ['name' => 'swapped'])->assertNotFound();
        $this->deleteJson("/api/v1/categories/{$category->id}/products/{$rp->id}")->assertStatus($crossOrganization ? 404 : 403);
        $this->assertSame('10.00', $rp->fresh()->price);
    }
}
