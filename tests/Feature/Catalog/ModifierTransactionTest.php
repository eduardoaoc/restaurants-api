<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CreateModifierGroupAction;
use App\Models\ModifierGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves CreateModifierGroupAction::execute() is wrapped in a real
 * transaction: if a translation insert fails partway through (a genuine
 * database unique-constraint violation, not a mock), the ModifierGroup row
 * that was already inserted does not survive.
 */
class ModifierTransactionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_modifier_group_does_not_persist_when_a_translation_insert_fails(): void
    {
        [, , , $restaurantProduct] = $this->createTenantWithRestaurantProduct();

        $action = app(CreateModifierGroupAction::class);

        try {
            // Two translations for the same locale: the first insert succeeds,
            // the second violates the unique(modifier_group_id, locale)
            // constraint — a real DB failure, not a mock.
            $action->execute($restaurantProduct, [
                'internal_name' => 'Broken Group',
                'max_select' => 1,
                'translations' => [
                    ['locale' => 'en', 'name' => 'First'],
                    ['locale' => 'en', 'name' => 'Duplicate'],
                ],
            ]);

            $this->fail('Expected a unique constraint violation to be thrown.');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseMissing('modifier_groups', ['internal_name' => 'Broken Group']);
        $this->assertSame(0, ModifierGroup::query()->where('restaurant_product_id', $restaurantProduct->id)->count());
    }
}
