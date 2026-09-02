<?php

namespace Tests\Feature\ModifierOption;

use App\Actions\Catalog\CreateModifierOptionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ModifierOptionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_modifier_option_with_translations(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Bacon',
                'price_delta' => 1.50,
                'available' => true,
                'sort_order' => 10,
                'translations' => [
                    ['locale' => 'es', 'name' => 'Bacon'],
                    ['locale' => 'en', 'name' => 'Bacon'],
                    ['locale' => 'pt', 'name' => 'Bacon'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_option.internal_name', 'Bacon')
            ->assertJsonPath('data.modifier_option.modifier_group_id', $group->id)
            ->assertJsonPath('data.modifier_option.price_delta', '1.50')
            ->assertJsonPath('data.modifier_option.available', true)
            ->assertJsonPath('data.modifier_option.sort_order', 10);

        $this->assertCount(3, $response->json('data.modifier_option.translations'));
    }

    public function test_price_delta_is_serialized_with_two_decimal_precision(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Queijo',
                'price_delta' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Cheese']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_option.price_delta', '1.00');
    }

    public function test_available_defaults_to_true_and_can_be_toggled(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Bacon',
                'translations' => [['locale' => 'en', 'name' => 'Bacon']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.modifier_option.available', true);

        $optionId = $response->json('data.modifier_option.id');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-options/{$optionId}", ['available' => false])
            ->assertOk()
            ->assertJsonPath('data.modifier_option.available', false);
    }

    public function test_negative_price_is_rejected(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Bacon',
                'price_delta' => -1.50,
                'translations' => [['locale' => 'en', 'name' => 'Bacon']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_delta');
    }

    public function test_listing_is_ordered_by_sort_order_then_id(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);

        $second = app(CreateModifierOptionAction::class)->execute($group, [
            'internal_name' => 'Second', 'sort_order' => 10,
            'translations' => [['locale' => 'en', 'name' => 'Second']],
        ]);
        $first = app(CreateModifierOptionAction::class)->execute($group, [
            'internal_name' => 'First', 'sort_order' => 1,
            'translations' => [['locale' => 'en', 'name' => 'First']],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/modifier-groups/{$group->id}/options")
            ->assertOk();

        $ids = collect($response->json('data.modifier_options'))->pluck('id');
        $this->assertSame([$first->id, $second->id], $ids->all());
    }

    public function test_owner_can_update_a_modifier_option(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);
        $option = $this->createModifierOption($group, 'Bacon', 1.50);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-options/{$option->id}", [
                'price_delta' => 2.00,
                'status' => 'inactive',
                'translations' => [['locale' => 'en', 'name' => 'Updated Bacon']],
            ])
            ->assertOk()
            ->assertJsonPath('data.modifier_option.price_delta', '2.00')
            ->assertJsonPath('data.modifier_option.status', 'inactive');

        $translations = collect($this->actingAs($owner, 'web')
            ->getJson("/api/v1/modifier-options/{$option->id}")
            ->json('data.modifier_option.translations'));

        $this->assertSame('Updated Bacon', $translations->firstWhere('locale', 'en')['name']);
    }

    public function test_modifier_group_id_cannot_be_changed_on_update(): void
    {
        [, $owner, , $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $groupA = $this->createModifierGroup($restaurantProduct, 'A', maxSelect: 5);
        $groupB = $this->createModifierGroup($restaurantProduct, 'B', maxSelect: 5);
        $option = $this->createModifierOption($groupA, 'Bacon');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/modifier-options/{$option->id}", ['modifier_group_id' => $groupB->id])
            ->assertOk();

        $this->assertDatabaseHas('modifier_options', [
            'id' => $option->id,
            'modifier_group_id' => $groupA->id,
        ]);
    }

    public function test_modifier_option_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , , $restaurantProductB] = $this->createTenantWithRestaurantProduct();
        $groupB = $this->createModifierGroup($restaurantProductB, 'Extras', maxSelect: 5);
        $optionB = $this->createModifierOption($groupB, 'Bacon');

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/modifier-options/{$optionB->id}")
            ->assertNotFound();
    }

    public function test_user_without_manage_products_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($restaurantProduct, 'Extras', maxSelect: 5);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Bacon',
                'translations' => [['locale' => 'en', 'name' => 'Bacon']],
            ])
            ->assertForbidden();
    }
}
