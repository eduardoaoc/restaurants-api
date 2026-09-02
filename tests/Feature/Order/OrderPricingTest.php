<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrderPricingTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_totals_match_hand_computed_expectation(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 12.90);

        $group = $this->createModifierGroup($rp, null, 0, 2, false);
        $bacon = $this->createModifierOption($group, null, 1.50);
        $cheese = $this->createModifierOption($group, null, 1.00);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [
                [
                    'restaurant_product_id' => $rp->id,
                    'quantity' => 2,
                    'modifier_option_ids' => [$bacon->id, $cheese->id],
                ],
            ],
        ])->assertStatus(201);

        // unit_total = 12.90 + 1.50 + 1.00 = 15.40; line_total = 15.40 * 2 = 30.80
        // subtotal = 12.90 * 2 = 25.80; modifiers_total = 2.50 * 2 = 5.00; total = 30.80
        $response->assertJson([
            'data' => [
                'subtotal' => '25.80',
                'modifiers_total' => '5.00',
                'total' => '30.80',
            ],
        ]);
    }

    public function test_no_float_rounding_error_with_dime_and_two_dime_prices(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $productA = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'A']]);
        $rpA = $this->createRestaurantProduct($restaurant, $productA, 0.10);
        $productB = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'B']]);
        $rpB = $this->createRestaurantProduct($restaurant, $productB, 0.20);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [
                ['restaurant_product_id' => $rpA->id, 'quantity' => 1],
                ['restaurant_product_id' => $rpB->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        // 0.10 + 0.20 must be exactly 0.30 — never 0.30000000000000004.
        $response->assertJson(['data' => ['subtotal' => '0.30', 'total' => '0.30']]);
    }

    public function test_monetary_fields_are_decimal_strings_not_json_numbers(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertIsString($data['subtotal']);
        $this->assertIsString($data['modifiers_total']);
        $this->assertIsString($data['total']);
        $this->assertSame('10.00', $data['subtotal']);
        $this->assertIsString($data['items'][0]['unit_price']);
        $this->assertIsString($data['items'][0]['line_total']);
    }

    public function test_zero_price_delta_modifier_still_formats_as_two_decimals(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 5.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $free = $this->createModifierOption($group, null, 0);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$free->id]]],
        ])->assertStatus(201);

        $this->assertSame('0.00', $response->json('data.items.0.modifiers.0.price_delta'));
    }
}
