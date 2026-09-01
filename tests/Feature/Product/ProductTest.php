<?php

namespace Tests\Feature\Product;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_product_with_translations(): void
    {
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Coca-Cola 330ml',
                'translations' => [
                    ['locale' => 'es', 'name' => 'Coca-Cola', 'description' => 'Refresco de cola'],
                    ['locale' => 'en', 'name' => 'Coca-Cola', 'description' => 'Cola soft drink'],
                    ['locale' => 'pt', 'name' => 'Coca-Cola', 'description' => 'Refrigerante de cola'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.product.internal_name', 'Coca-Cola 330ml');

        $translations = collect($response->json('data.product.translations'));
        $this->assertCount(3, $translations);
        $this->assertEqualsCanonicalizing(['es', 'en', 'pt'], $translations->pluck('locale')->all());
    }

    public function test_organization_id_is_defined_by_the_backend(): void
    {
        [$organization, $owner] = $this->createTenant();
        [$otherOrganization] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'organization_id' => $otherOrganization->id,
                'translations' => [['locale' => 'en', 'name' => 'Water']],
            ])
            ->assertCreated();

        $this->assertSame($organization->id, $response->json('data.product.organization_id'));
    }

    public function test_a_product_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/products/{$productB->id}")
            ->assertNotFound();
    }

    public function test_owner_can_update_a_product(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, 'Old Name', [
            ['locale' => 'en', 'name' => 'Old'],
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", [
                'internal_name' => 'New Name',
                'translations' => [['locale' => 'en', 'name' => 'New']],
            ])
            ->assertOk()
            ->assertJsonPath('data.product.internal_name', 'New Name');

        $translations = collect($this->actingAs($owner, 'web')
            ->getJson("/api/v1/products/{$product->id}")
            ->json('data.product.translations'));

        $this->assertSame('New', $translations->firstWhere('locale', 'en')['name']);
    }

    public function test_updating_only_one_locale_does_not_remove_the_others(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization, 'Product', [
            ['locale' => 'en', 'name' => 'English'],
            ['locale' => 'es', 'name' => 'Spanish'],
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/products/{$product->id}", [
                'translations' => [['locale' => 'en', 'name' => 'Updated English']],
            ])
            ->assertOk();

        $translations = collect($this->actingAs($owner, 'web')
            ->getJson("/api/v1/products/{$product->id}")
            ->json('data.product.translations'));

        $this->assertCount(2, $translations);
        $this->assertSame('Updated English', $translations->firstWhere('locale', 'en')['name']);
        $this->assertSame('Spanish', $translations->firstWhere('locale', 'es')['name']);
    }

    public function test_user_without_manage_products_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water']],
            ])
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_duplicate_locales_in_translations_are_rejected(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [
                    ['locale' => 'en', 'name' => 'Water'],
                    ['locale' => 'en', 'name' => 'Water again'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('translations');
    }

    public function test_no_sensitive_user_data_is_ever_involved_in_product_responses(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'Water',
                'translations' => [['locale' => 'en', 'name' => 'Water']],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.product.password')
            ->assertJsonMissingPath('data.product.owner')
            ->assertJsonMissingPath('data.product.created_by');
    }
}
