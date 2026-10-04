<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class NormalizationInputTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public static function fields(): array
    {
        return array_map(fn ($field) => [$field], ['organization_slug', 'restaurant_slug', 'category_slug', 'staff_email', 'staff_sub_id']);
    }

    #[DataProvider('fields')]
    public function test_normalization_never_casts_array_input_to_string(string $field): void
    {
        $this->seedRolesAndPermissions();
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);
        $this->withHeader('Origin', 'http://localhost:5173')->actingAs($owner, 'web');
        [$method, $url, $payload] = match ($field) {
            'organization_slug' => ['PATCH', '/organization', ['slug' => ['bad']]],
            'restaurant_slug' => ['POST', '/restaurants', ['name' => 'valid', 'slug' => ['bad']]],
            'category_slug' => ['POST', "/restaurants/{$restaurant->id}/categories", ['slug' => ['bad'], 'translations' => [['locale' => 'en', 'name' => 'valid']]]],
            'staff_email' => ['POST', '/staff', ['name' => 'valid', 'email' => ['bad'], 'password' => 'password123', 'role' => 'waiter', 'restaurant_assignments' => [['restaurant_id' => $restaurant->id, 'sub_id' => 'valid']]]],
            'staff_sub_id' => ['POST', '/staff', ['name' => 'valid', 'email' => 'new@example.test', 'password' => 'password123', 'role' => 'waiter', 'restaurant_assignments' => [['restaurant_id' => $restaurant->id, 'sub_id' => ['bad']]]]],
        };
        $this->json($method, '/api/v1'.$url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseCount('users', 1);
    }
}
