<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies DemoRestaurantSeeder: it only runs in local/testing, it builds
 * the full demo scenario the operational dashboard and Analytics tab need,
 * and re-running it does not pile up duplicate staff/tables/sessions.
 */
class DemoRestaurantSeederTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_EMAILS = [
        'manager@aforo.test', 'waiter1@aforo.test', 'waiter2@aforo.test', 'waiter3@aforo.test',
        'waiter4@aforo.test', 'kitchen1@aforo.test', 'kitchen2@aforo.test', 'cashier@aforo.test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_it_refuses_to_run_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        (new DemoRestaurantSeeder)->run();
    }

    public function test_it_creates_the_full_demo_scenario_and_feeds_the_dashboard_endpoints(): void
    {
        $this->seed(DemoRestaurantSeeder::class);

        $restaurant = Restaurant::query()->where('slug', 'aforo-malvarrosa')->firstOrFail();
        $owner = User::query()->where('email', 'owner@aforo.test')->firstOrFail();

        $this->assertSame('active', $owner->status);
        $this->assertTrue($owner->organizations()->whereKey($restaurant->organization_id)->exists());
        $this->assertTrue($owner->userRoles()->where('organization_id', $restaurant->organization_id)->whereNull('restaurant_id')->exists());

        $this->assertSame(14, $restaurant->tables()->count());
        $this->assertSame(1, $restaurant->floors()->count());
        $this->assertSame(2, $restaurant->zones()->count());
        $this->assertSame(13, $restaurant->restaurantProducts()->count());

        foreach (self::STAFF_EMAILS as $email) {
            $this->assertTrue(User::query()->where('email', $email)->exists(), "Missing staff member {$email}");
        }

        // --- Every tenant role has a real user with the correct role/membership ---
        $roleByEmail = [
            'owner@aforo.test' => 'owner',
            'manager@aforo.test' => 'manager',
            'waiter1@aforo.test' => 'waiter',
            'kitchen1@aforo.test' => 'kitchen',
            'cashier@aforo.test' => 'cashier',
        ];

        foreach ($roleByEmail as $email => $roleSlug) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertTrue(
                $user->userRoles()->whereHas('role', fn ($q) => $q->where('slug', $roleSlug))->exists(),
                "{$email} is missing the {$roleSlug} role assignment",
            );
            $this->assertTrue(
                $user->organizations()->whereKey($restaurant->organization_id)->exists(),
                "{$email} is missing organization membership",
            );

            if ($email !== 'owner@aforo.test') {
                $this->assertTrue(
                    $restaurant->users()->whereKey($user->id)->exists(),
                    "{$email} is missing restaurant_users membership",
                );
            }
        }

        // --- Canonical QR test table: Mesa 01 has a usable public token ---
        $mesa01 = Table::query()->where('restaurant_id', $restaurant->id)->where('number', 1)->firstOrFail();
        $this->assertNotEmpty($mesa01->public_token);
        $this->assertSame('active', $mesa01->status);

        // --- Menu / categories / products / modifiers the public QR flow needs ---
        $menu = Menu::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertSame('active', $menu->status);
        $this->assertSame(4, Category::query()->where('menu_id', $menu->id)->count());
        $this->assertSame(13, CategoryProduct::query()->whereHas('category', fn ($q) => $q->where('menu_id', $menu->id))->count());

        $hamburguesaRp = $restaurant->restaurantProducts()
            ->whereHas('product', fn ($q) => $q->where('sku', 'DEMO-HAMBURGUESA'))
            ->firstOrFail();
        $this->assertSame(2, ModifierGroup::query()->where('restaurant_product_id', $hamburguesaRp->id)->count());
        $this->assertTrue(
            ModifierGroup::query()->where('restaurant_product_id', $hamburguesaRp->id)->where('required', true)->exists(),
            'Hamburguesa AFORO is missing its required single-choice modifier group',
        );
        $requiredGroup = ModifierGroup::query()->where('restaurant_product_id', $hamburguesaRp->id)->where('required', true)->firstOrFail();
        $this->assertSame(3, ModifierOption::query()->where('modifier_group_id', $requiredGroup->id)->count());

        $this->assertSame(8, TableSession::query()->where('restaurant_id', $restaurant->id)->whereNull('closed_at')->count());
        $this->assertGreaterThan(0, Order::query()->where('restaurant_id', $restaurant->id)->whereIn('status', Order::openStatuses())->count());
        $this->assertGreaterThan(100, TableSession::query()->where('restaurant_id', $restaurant->id)->where('status', 'closed')->count());
        $this->assertGreaterThan(0, PaymentRecord::query()->where('restaurant_id', $restaurant->id)->count());

        $live = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertGreaterThan(0, $live->json('data.summary.tables.occupied'));
        $this->assertGreaterThan(0, $live->json('data.summary.active_guests'));
        $this->assertGreaterThan(0, $live->json('data.summary.orders.active'));
        $this->assertGreaterThan(0, $live->json('data.summary.staff.active'));
        $this->assertNotEmpty($live->json('data.alerts'));

        $from = now('Europe/Madrid')->subDays(60)->toDateString();
        $to = now('Europe/Madrid')->toDateString();

        $analytics = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from={$from}&to={$to}")
            ->assertOk();

        $this->assertNotEquals('0.00', $analytics->json('data.summary.revenue'));
        $this->assertGreaterThan(0, $analytics->json('data.summary.orders_count'));
        $this->assertNotEmpty($analytics->json('data.products.top_by_quantity'));
        $this->assertNotEmpty($analytics->json('data.staff'));
    }

    public function test_running_it_twice_does_not_duplicate_the_scenario(): void
    {
        $this->seed(DemoRestaurantSeeder::class);
        $this->seed(DemoRestaurantSeeder::class);

        $restaurant = Restaurant::query()->where('slug', 'aforo-malvarrosa')->firstOrFail();

        $this->assertSame(1, Restaurant::query()->where('slug', 'aforo-malvarrosa')->count());
        $this->assertSame(14, $restaurant->tables()->count());
        $this->assertSame(1, User::query()->where('email', 'owner@aforo.test')->count());
        $this->assertSame(8, User::query()->whereIn('email', self::STAFF_EMAILS)->count());
        $this->assertSame(8, TableSession::query()->where('restaurant_id', $restaurant->id)->whereNull('closed_at')->count());

        // Catalog wiring (menu/categories/category_products/modifiers) must
        // not pile up either.
        $this->assertSame(1, Menu::query()->where('restaurant_id', $restaurant->id)->count());
        $menu = Menu::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertSame(4, Category::query()->where('menu_id', $menu->id)->count());
        $this->assertSame(13, CategoryProduct::query()->whereHas('category', fn ($q) => $q->where('menu_id', $menu->id))->count());

        $hamburguesaRp = $restaurant->restaurantProducts()
            ->whereHas('product', fn ($q) => $q->where('sku', 'DEMO-HAMBURGUESA'))
            ->firstOrFail();
        $this->assertSame(2, ModifierGroup::query()->where('restaurant_product_id', $hamburguesaRp->id)->count());
        $this->assertSame(7, ModifierOption::query()->whereHas('modifierGroup', fn ($q) => $q->where('restaurant_product_id', $hamburguesaRp->id))->count());
    }

    /**
     * Regression: a demo staff member manually deactivated in their
     * organization (e.g. while testing PATCH /staff/{user}) must come back
     * ACTIVE the next time the demo is reset — a stale "inactive" pivot
     * from a previous testing session silently breaks GET /auth/context
     * for that user (organizations comes back empty), even though the
     * account, role and restaurant assignment are all otherwise intact.
     */
    public function test_rerunning_it_reactivates_a_manually_deactivated_staff_member(): void
    {
        $this->seed(DemoRestaurantSeeder::class);

        $kitchen = User::query()->where('email', 'kitchen1@aforo.test')->firstOrFail();
        $organization = Organization::query()->where('slug', 'aforo-demo')->firstOrFail();

        OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $kitchen->id)
            ->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        $this->seed(DemoRestaurantSeeder::class);

        $this->assertSame(
            OrganizationUser::STATUS_ACTIVE,
            OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $kitchen->id)
                ->value('status'),
        );
    }
}
