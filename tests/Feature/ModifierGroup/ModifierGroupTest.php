<?php

namespace Tests\Feature\ModifierGroup;

use App\Actions\Catalog\CreateModifierGroupAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ModifierGroupTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_modifier_group_with_translations(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Extras',
                'min_select' => 0,
                'max_select' => 5,
                'required' => false,
                'sort_order' => 20,
                'translations' => [
                    ['locale' => 'es', 'name' => 'Extras'],
                    ['locale' => 'en', 'name' => 'Extras'],
                    ['locale' => 'pt', 'name' => 'Adicionais'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_group.internal_name', 'Extras')
            ->assertJsonPath('data.modifier_group.restaurant_product_id', $restaurantProduct->id)
            ->assertJsonPath('data.modifier_group.min_select', 0)
            ->assertJsonPath('data.modifier_group.max_select', 5)
            ->assertJsonPath('data.modifier_group.required', false)
            ->assertJsonPath('data.modifier_group.sort_order', 20);

        $translations = collect($response->json('data.modifier_group.translations'));
        $this->assertSame('Adicionais', $translations->firstWhere('locale', 'pt')['name']);
    }

    public function test_restaurant_product_id_from_payload_is_ignored(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Extras',
                'max_select' => 5,
                'restaurant_product_id' => 999999,
                'translations' => [['locale' => 'en', 'name' => 'Extras']],
            ])
            ->assertCreated();

        $this->assertSame($restaurantProduct->id, $response->json('data.modifier_group.restaurant_product_id'));
    }

    public function test_required_true_with_min_select_zero_is_rejected(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Ponto da carne',
                'required' => true,
                'min_select' => 0,
                'max_select' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Cooking point']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('min_select');
    }

    public function test_max_select_less_than_min_select_is_rejected(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Acompanhamentos',
                'min_select' => 3,
                'max_select' => 2,
                'translations' => [['locale' => 'en', 'name' => 'Sides']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_select');
    }

    public function test_required_multiple_choice_is_accepted(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Escolha 2 acompanhamentos',
                'required' => true,
                'min_select' => 2,
                'max_select' => 2,
                'translations' => [['locale' => 'en', 'name' => 'Pick 2 sides']],
            ])
            ->assertCreated();
    }

    public function test_listing_is_ordered_by_sort_order_then_id(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        // Created out of order and with explicit sort_order values, so the
        // response order can only come from ORDER BY sort_order, id.
        $second = app(CreateModifierGroupAction::class)->execute($restaurantProduct, [
            'internal_name' => 'Second', 'max_select' => 1, 'sort_order' => 10,
            'translations' => [['locale' => 'en', 'name' => 'Second']],
        ]);
        $first = app(CreateModifierGroupAction::class)->execute($restaurantProduct, [
            'internal_name' => 'First', 'max_select' => 1, 'sort_order' => 1,
            'translations' => [['locale' => 'en', 'name' => 'First']],
        ]);
        $thirdTiedByIdAfterSecond = app(CreateModifierGroupAction::class)->execute($restaurantProduct, [
            'internal_name' => 'Third', 'max_select' => 1, 'sort_order' => 10,
            'translations' => [['locale' => 'en', 'name' => 'Third']],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups")
            ->assertOk();

        $ids = collect($response->json('data.modifier_groups'))->pluck('id');

        // sort_order 1 first, then the two tied at sort_order 10 in id order.
        $this->assertSame([$first->id, $second->id, $thirdTiedByIdAfterSecond->id], $ids->all());
    }

    public function test_owner_can_update_a_modifier_group(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 3);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-groups/{$group->id}", [
                'max_select' => 4,
                'status' => 'inactive',
                'translations' => [['locale' => 'en', 'name' => 'Updated Extras']],
            ])
            ->assertOk()
            ->assertJsonPath('data.modifier_group.max_select', 4)
            ->assertJsonPath('data.modifier_group.status', 'inactive');

        $translations = collect($this->actingAs($owner, 'web')
            ->getJson("/api/v1/modifier-groups/{$group->id}")
            ->json('data.modifier_group.translations'));

        $this->assertSame('Updated Extras', $translations->firstWhere('locale', 'en')['name']);
    }

    public function test_update_keeping_required_true_still_enforces_min_select(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Ponto', minSelect: 1, maxSelect: 1, required: true);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-groups/{$group->id}", ['min_select' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('min_select');
    }

    public function test_restaurant_product_id_cannot_be_changed_on_update(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 1);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-groups/{$group->id}", ['restaurant_product_id' => 999999])
            ->assertOk();

        $this->assertDatabaseHas('modifier_groups', [
            'id' => $group->id,
            'restaurant_product_id' => $restaurantProduct->id,
        ]);
    }

    public function test_modifier_group_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 1);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/modifier-groups/{$groupB->id}")
            ->assertNotFound();
    }

    public function test_user_without_manage_products_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups", [
                'internal_name' => 'Extras',
                'max_select' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Extras']],
            ])
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurant-products/{$restaurantProduct->id}/modifier-groups")
            ->assertForbidden();
    }
}
