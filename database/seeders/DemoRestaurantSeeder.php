<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Staff\CreateStaffAction;
use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Floor;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\RestaurantSettings;
use App\Models\Role;
use App\Models\StaffReview;
use App\Models\StaffShift;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WaiterCall;
use App\Models\Zone;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Populates one complete, domain-valid demo restaurant ("AFORO Malvarrosa")
 * for local/testing use — enough coherent data to exercise every section of
 * the restaurants-web dashboard's Operations Live and Analytics tabs.
 *
 * Every row created here goes through the same Eloquent models (and their
 * `booted()` invariants — restaurant/table/session coherence, etc.) the
 * real application uses; the only thing skipped, deliberately, is the
 * Action layer's HTTP-facing concerns (authorization plumbing, AuditLog,
 * realtime events) for the bulk of the operational history, so that ~45
 * days of sessions/orders/payments can be generated in one request-free
 * pass with fully controllable timestamps. CreateStaffAction and
 * CreateProductAction ARE used for onboarding — that orchestration (wiring
 * organization_users/restaurant_users/user_roles, or a Product plus its
 * translations) is exactly the kind of multi-table consistency a seeder
 * should not reimplement.
 *
 * Every derived figure — table primary_status, alerts, health_score,
 * bottleneck, occupancy, peak hours, top products — is left entirely to
 * the real backend (OperationAlertBuilder, OperationHealthCalculator,
 * the Analytics support classes, ...). This seeder only ever writes the
 * underlying domain facts those calculators read.
 */
class DemoRestaurantSeeder extends Seeder
{
    private const ORG_SLUG = 'aforo-demo';

    private const RESTAURANT_SLUG = 'aforo-malvarrosa';

    private const OWNER_EMAIL = 'owner@aforo.test';

    private const PASSWORD = 'password';

    private const TIMEZONE = 'Europe/Madrid';

    /** How many days of closed-session history to (re)generate before "today". */
    private const HISTORY_DAYS = 45;

    private const STAFF = [
        ['slug' => 'manager', 'role' => 'manager', 'name' => 'Carmen Vidal', 'email' => 'manager@aforo.test', 'sub_id' => 'MGR-1'],
        ['slug' => 'waiter1', 'role' => 'waiter', 'name' => 'Lorena Martín', 'email' => 'waiter1@aforo.test', 'sub_id' => 'W-1'],
        ['slug' => 'waiter2', 'role' => 'waiter', 'name' => 'Mateo Ruiz', 'email' => 'waiter2@aforo.test', 'sub_id' => 'W-2'],
        ['slug' => 'waiter3', 'role' => 'waiter', 'name' => 'Sofía García', 'email' => 'waiter3@aforo.test', 'sub_id' => 'W-3'],
        ['slug' => 'waiter4', 'role' => 'waiter', 'name' => 'Diego Navarro', 'email' => 'waiter4@aforo.test', 'sub_id' => 'W-4'],
        ['slug' => 'kitchen1', 'role' => 'kitchen', 'name' => 'Pablo Torres', 'email' => 'kitchen1@aforo.test', 'sub_id' => 'K-1'],
        ['slug' => 'kitchen2', 'role' => 'kitchen', 'name' => 'Lucía Romero', 'email' => 'kitchen2@aforo.test', 'sub_id' => 'K-2'],
        ['slug' => 'cashier', 'role' => 'cashier', 'name' => 'Javier Costa', 'email' => 'cashier@aforo.test', 'sub_id' => 'C-1'],
    ];

    private const ZONES = [
        'interior' => ['name' => 'Interior', 'sort_order' => 0],
        'terraza' => ['name' => 'Terraza', 'sort_order' => 1],
    ];

    private const TABLES = [
        ['number' => 1, 'name' => 'Mesa 01', 'zone' => 'interior', 'capacity' => 4, 'shape' => 'round', 'x' => 0.12, 'y' => 0.25, 'width' => 80, 'height' => 80],
        ['number' => 2, 'name' => 'Mesa 02', 'zone' => 'interior', 'capacity' => 2, 'shape' => 'square', 'x' => 0.35, 'y' => 0.25, 'width' => 70, 'height' => 70],
        ['number' => 3, 'name' => 'Mesa 03', 'zone' => 'interior', 'capacity' => 4, 'shape' => 'round', 'x' => 0.58, 'y' => 0.25, 'width' => 80, 'height' => 80],
        ['number' => 4, 'name' => 'Mesa 04', 'zone' => 'interior', 'capacity' => 6, 'shape' => 'rectangle', 'x' => 0.81, 'y' => 0.25, 'width' => 120, 'height' => 70],
        ['number' => 5, 'name' => 'Mesa 05', 'zone' => 'interior', 'capacity' => 2, 'shape' => 'square', 'x' => 0.12, 'y' => 0.65, 'width' => 70, 'height' => 70],
        ['number' => 6, 'name' => 'Mesa 06', 'zone' => 'interior', 'capacity' => 4, 'shape' => 'round', 'x' => 0.35, 'y' => 0.65, 'width' => 80, 'height' => 80],
        ['number' => 7, 'name' => 'Mesa 07', 'zone' => 'interior', 'capacity' => 4, 'shape' => 'square', 'x' => 0.58, 'y' => 0.65, 'width' => 80, 'height' => 80],
        ['number' => 8, 'name' => 'Mesa 08', 'zone' => 'interior', 'capacity' => 6, 'shape' => 'rectangle', 'x' => 0.81, 'y' => 0.65, 'width' => 120, 'height' => 70],
        ['number' => 9, 'name' => 'Mesa 09', 'zone' => 'terraza', 'capacity' => 2, 'shape' => 'round', 'x' => 0.15, 'y' => 0.3, 'width' => 70, 'height' => 70],
        ['number' => 10, 'name' => 'Mesa 10', 'zone' => 'terraza', 'capacity' => 4, 'shape' => 'square', 'x' => 0.4, 'y' => 0.3, 'width' => 80, 'height' => 80],
        ['number' => 11, 'name' => 'Mesa 11', 'zone' => 'terraza', 'capacity' => 4, 'shape' => 'square', 'x' => 0.65, 'y' => 0.3, 'width' => 80, 'height' => 80],
        ['number' => 12, 'name' => 'Mesa 12', 'zone' => 'terraza', 'capacity' => 2, 'shape' => 'round', 'x' => 0.85, 'y' => 0.3, 'width' => 70, 'height' => 70],
        ['number' => 13, 'name' => 'Mesa 13', 'zone' => 'terraza', 'capacity' => 6, 'shape' => 'rectangle', 'x' => 0.3, 'y' => 0.7, 'width' => 120, 'height' => 70],
        ['number' => 14, 'name' => 'Mesa 14', 'zone' => 'terraza', 'capacity' => 4, 'shape' => 'round', 'x' => 0.6, 'y' => 0.7, 'width' => 80, 'height' => 80],
    ];

    private const PRODUCTS = [
        'croquetas' => ['sku' => 'DEMO-CROQUETAS', 'name' => 'Croquetas de jamón', 'price' => '7.50'],
        'bravas' => ['sku' => 'DEMO-BRAVAS', 'name' => 'Patatas bravas', 'price' => '6.90'],
        'ensalada' => ['sku' => 'DEMO-ENSALADA', 'name' => 'Ensalada mediterránea', 'price' => '8.50'],
        'paella' => ['sku' => 'DEMO-PAELLA', 'name' => 'Paella Valenciana', 'price' => '16.50'],
        'senyoret' => ['sku' => 'DEMO-SENYORET', 'name' => 'Arroz del senyoret', 'price' => '17.00'],
        'hamburguesa' => ['sku' => 'DEMO-HAMBURGUESA', 'name' => 'Hamburguesa AFORO', 'price' => '13.50'],
        'lubina' => ['sku' => 'DEMO-LUBINA', 'name' => 'Lubina a la sal', 'price' => '19.00'],
        'agua' => ['sku' => 'DEMO-AGUA', 'name' => 'Agua mineral', 'price' => '2.50'],
        'cocacola' => ['sku' => 'DEMO-COCACOLA', 'name' => 'Coca-Cola', 'price' => '3.00'],
        'cerveza' => ['sku' => 'DEMO-CERVEZA', 'name' => 'Cerveza', 'price' => '3.50'],
        'vino' => ['sku' => 'DEMO-VINO', 'name' => 'Vino (copa)', 'price' => '4.00'],
        'tarta' => ['sku' => 'DEMO-TARTA', 'name' => 'Tarta de queso', 'price' => '5.50'],
        'crema' => ['sku' => 'DEMO-CREMA', 'name' => 'Crema catalana', 'price' => '5.00'],
    ];

    /**
     * Extra locales for the handful of catalog rows used to visually prove
     * the public menu's language switcher (Passo 3.2 prep) — the rest of
     * the catalog only needs its es-ES translation. Keyed by the same
     * product/category key used in PRODUCTS/CATEGORIES below.
     */
    private const EXTRA_LOCALE_NAMES = [
        'products' => [
            'croquetas' => ['ca-ES-valencia' => 'Croquetes de pernil', 'en-GB' => 'Ham croquettes'],
            'hamburguesa' => ['ca-ES-valencia' => 'Hamburguesa AFORO', 'en-GB' => 'AFORO Burger'],
        ],
        'categories' => [
            'entrantes' => ['ca-ES-valencia' => 'Entrants', 'en-GB' => 'Starters'],
            'principales' => ['ca-ES-valencia' => 'Principals', 'en-GB' => 'Mains'],
            'bebidas' => ['ca-ES-valencia' => 'Begudes', 'en-GB' => 'Drinks'],
            'postres' => ['ca-ES-valencia' => 'Postres', 'en-GB' => 'Desserts'],
        ],
    ];

    /** Menu structure: which of PRODUCTS lands under each category, in order. */
    private const CATEGORIES = [
        'entrantes' => ['sort_order' => 0, 'name' => 'Entrantes', 'products' => ['croquetas', 'bravas', 'ensalada']],
        'principales' => ['sort_order' => 1, 'name' => 'Principales', 'products' => ['paella', 'senyoret', 'hamburguesa', 'lubina']],
        'bebidas' => ['sort_order' => 2, 'name' => 'Bebidas', 'products' => ['agua', 'cocacola', 'cerveza', 'vino']],
        'postres' => ['sort_order' => 3, 'name' => 'Postres', 'products' => ['tarta', 'crema']],
    ];

    /**
     * Modifiers on "Hamburguesa AFORO" — the one product in the demo
     * catalog exercising both modifier shapes the public UI needs to
     * prove: a required single-choice group and an optional multi-choice
     * one. Every other demo product deliberately has none.
     */
    private const HAMBURGUESA_MODIFIER_GROUPS = [
        'punto_carne' => [
            'internal_name' => 'Punto de la carne',
            'min_select' => 1,
            'max_select' => 1,
            'required' => true,
            'sort_order' => 0,
            'names' => ['es-ES' => 'Punto de la carne', 'ca-ES-valencia' => 'Punt de la carn', 'en-GB' => 'Doneness'],
            'options' => [
                'poco_hecho' => ['price_delta' => '0.00', 'sort_order' => 0, 'names' => ['es-ES' => 'Poco hecho', 'ca-ES-valencia' => 'Poc fet', 'en-GB' => 'Rare']],
                'al_punto' => ['price_delta' => '0.00', 'sort_order' => 1, 'names' => ['es-ES' => 'Al punto', 'ca-ES-valencia' => 'Al punt', 'en-GB' => 'Medium']],
                'muy_hecho' => ['price_delta' => '0.00', 'sort_order' => 2, 'names' => ['es-ES' => 'Muy hecho', 'ca-ES-valencia' => 'Molt fet', 'en-GB' => 'Well done']],
            ],
        ],
        'extras' => [
            'internal_name' => 'Extras',
            'min_select' => 0,
            'max_select' => 4,
            'required' => false,
            'sort_order' => 1,
            'names' => ['es-ES' => 'Extras', 'ca-ES-valencia' => 'Extres', 'en-GB' => 'Extras'],
            'options' => [
                'queso' => ['price_delta' => '1.00', 'sort_order' => 0, 'names' => ['es-ES' => 'Queso extra', 'ca-ES-valencia' => 'Formatge extra', 'en-GB' => 'Extra cheese']],
                'bacon' => ['price_delta' => '1.50', 'sort_order' => 1, 'names' => ['es-ES' => 'Bacon', 'ca-ES-valencia' => 'Bacon', 'en-GB' => 'Bacon']],
                'huevo' => ['price_delta' => '1.00', 'sort_order' => 2, 'names' => ['es-ES' => 'Huevo frito', 'ca-ES-valencia' => 'Ou fregit', 'en-GB' => 'Fried egg']],
                'cebolla' => ['price_delta' => '0.80', 'sort_order' => 3, 'names' => ['es-ES' => 'Cebolla caramelizada', 'ca-ES-valencia' => 'Ceba caramel·litzada', 'en-GB' => 'Caramelised onion']],
            ],
        ],
    ];

    /** Deterministic "what sells" weighting — paella/croquetas appear most, feeding Analytics' top_products. */
    private const POPULARITY_CYCLE = [
        'paella', 'croquetas', 'cerveza', 'bravas', 'agua', 'paella', 'croquetas', 'hamburguesa',
        'cocacola', 'tarta', 'bravas', 'paella', 'croquetas', 'vino', 'ensalada', 'cerveza',
        'senyoret', 'crema', 'agua', 'lubina',
    ];

    /**
     * Sessions-started-per-hour by ISO weekday (1=Monday..7=Sunday) —
     * deterministic, no RNG (see the task's own preference for a
     * reproducible dataset). Monday/Tuesday quiet, Friday/Saturday busy,
     * Sunday moderate; 21:00 is the most frequent hour across the week,
     * which is what makes it Analytics' peak hour.
     */
    private const SCHEDULE = [
        1 => ['hours' => [13, 20, 21]],
        2 => ['hours' => [14, 20, 21]],
        3 => ['hours' => [13, 14, 20, 21]],
        4 => ['hours' => [13, 14, 20, 21, 22]],
        5 => ['hours' => [13, 14, 15, 20, 21, 21, 22]],
        6 => ['hours' => [13, 14, 15, 20, 21, 21, 22, 23]],
        7 => ['hours' => [13, 14, 15, 20, 21]],
    ];

    /** @var array<string, RestaurantProduct> */
    private array $restaurantProductsByKey = [];

    /** @var array<int, string> */
    private array $productNamesByRestaurantProductId = [];

    private int $productCursor = 0;

    public function run(): void
    {
        // Dev/test only — never allowed to touch a production database,
        // regardless of how it's invoked. Mirrors PlatformAdminDevSeeder's
        // guard, except this one throws: an accidental
        // `db:seed --class=DemoRestaurantSeeder` in production must fail
        // loudly, not silently no-op.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoRestaurantSeeder must only run in the local or testing environment.');
        }

        DB::transaction(function () {
            $organization = $this->createOrganization();
            $restaurant = $this->createRestaurant($organization);
            $owner = $this->createOwner($organization);
            $staff = $this->createStaff($organization, $restaurant, $owner);
            $tables = $this->createFloorPlan($restaurant);
            $this->createCatalog($organization, $restaurant);

            // Idempotency strategy: the demo Organization/Restaurant/staff/
            // floor plan/catalog above are all located by a stable slug/
            // email/number and upserted in place. Operational history
            // (sessions, orders, payments, requests, calls, shifts,
            // reviews) has no such natural key, so instead this wipes only
            // the rows already scoped to THIS restaurant_id and rebuilds
            // them fresh — never a global truncate, never another
            // tenant's data.
            $this->resetOperationalData($restaurant);

            $this->seedHistoricalOperations($restaurant, $organization, $owner, $tables, $staff);
            $this->seedTodayOperations($restaurant, $tables, $staff);
        });
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->updateOrCreate(
            ['slug' => self::ORG_SLUG],
            [
                'name' => 'AFORO Demo',
                'status' => Organization::STATUS_ACTIVE,
                'plan' => Organization::PLAN_PRO,
                'subscription_status' => Organization::SUBSCRIPTION_STATUS_ACTIVE,
            ],
        );
    }

    private function createRestaurant(Organization $organization): Restaurant
    {
        $restaurant = Restaurant::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'slug' => self::RESTAURANT_SLUG],
            ['name' => 'AFORO Malvarrosa', 'status' => Restaurant::STATUS_ACTIVE],
        );

        if (! $restaurant->settings()->exists()) {
            RestaurantSettings::createDefaultsFor($restaurant);
        }

        $restaurant->settings()->update([
            'timezone' => self::TIMEZONE,
            'currency' => RestaurantSettings::DEFAULT_CURRENCY,
            'customer_ordering_enabled' => true,
            'customer_order_requires_approval' => false,
            'waiter_call_enabled' => true,
            'bill_request_enabled' => true,
        ]);

        return $restaurant->fresh();
    }

    private function createOwner(Organization $organization): User
    {
        $owner = User::query()->updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'Elena Ferrer', 'password' => self::PASSWORD, 'status' => User::STATUS_ACTIVE],
        );

        // syncWithoutDetaching (not attach-if-missing): also self-heals an
        // owner whose organization_users.status drifted to 'inactive' from
        // earlier manual testing of the staff-deactivation feature — a
        // demo reset must always come back up usable, never inherit
        // whatever state the last testing session left behind.
        $organization->users()->syncWithoutDetaching([$owner->id => ['status' => OrganizationUser::STATUS_ACTIVE]]);

        $ownerRole = Role::query()->where('slug', 'owner')->firstOrFail();

        // Organization-wide role assignment (restaurant_id null) — the
        // real owner-onboarding shape (see RestaurantScope), not a
        // restaurant_users row.
        UserRole::query()->firstOrCreate([
            'user_id' => $owner->id,
            'role_id' => $ownerRole->id,
            'organization_id' => $organization->id,
            'restaurant_id' => null,
        ]);

        return $owner;
    }

    /**
     * @return array<string, User>
     */
    private function createStaff(Organization $organization, Restaurant $restaurant, User $actor): array
    {
        $users = [];

        foreach (self::STAFF as $spec) {
            $user = User::query()->where('email', $spec['email'])->first();

            if (! $user) {
                $user = app(CreateStaffAction::class)->execute($organization, [
                    'name' => $spec['name'],
                    'email' => $spec['email'],
                    'password' => self::PASSWORD,
                    'role' => $spec['role'],
                    'restaurant_assignments' => [
                        ['restaurant_id' => $restaurant->id, 'sub_id' => $spec['sub_id']],
                    ],
                ], $actor);
            } else {
                // Re-run: self-heal the links instead of re-invoking the
                // Action (which always creates a brand new User and would
                // collide on the unique email). syncWithoutDetaching also
                // resets organization_users.status back to 'active' if a
                // previous manual test of the staff-deactivation feature
                // left this demo user deactivated — see createOwner().
                $user->update(['status' => User::STATUS_ACTIVE, 'password' => self::PASSWORD]);

                $organization->users()->syncWithoutDetaching([$user->id => ['status' => OrganizationUser::STATUS_ACTIVE]]);

                if (! $restaurant->users()->whereKey($user->id)->exists()) {
                    $restaurant->users()->attach($user->id, ['sub_id' => $spec['sub_id']]);
                }

                $role = Role::query()->where('slug', $spec['role'])->firstOrFail();

                UserRole::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'organization_id' => $organization->id,
                    'restaurant_id' => $restaurant->id,
                ]);
            }

            $users[$spec['slug']] = $user;
        }

        return $users;
    }

    /**
     * @return array<int, Table>
     */
    private function createFloorPlan(Restaurant $restaurant): array
    {
        $floor = Floor::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Planta principal'],
            ['sort_order' => 0, 'is_active' => true],
        );

        $zones = [];

        foreach (self::ZONES as $key => $spec) {
            $zones[$key] = Zone::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'floor_id' => $floor->id, 'name' => $spec['name']],
                ['sort_order' => $spec['sort_order'], 'is_active' => true],
            );
        }

        $tables = [];

        foreach (self::TABLES as $spec) {
            $existing = Table::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('number', $spec['number'])
                ->first();

            $tables[$spec['number']] = Table::query()->updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'number' => $spec['number']],
                [
                    'name' => $spec['name'],
                    'public_token' => $existing->public_token ?? Table::generateUniquePublicToken(),
                    'status' => 'active',
                    'zone_id' => $zones[$spec['zone']]->id,
                    'capacity' => $spec['capacity'],
                    'layout_x' => $spec['x'],
                    'layout_y' => $spec['y'],
                    'layout_rotation' => 0,
                    'layout_shape' => $spec['shape'],
                    'layout_width' => $spec['width'],
                    'layout_height' => $spec['height'],
                ],
            );
        }

        return $tables;
    }

    private function createCatalog(Organization $organization, Restaurant $restaurant): void
    {
        foreach (self::PRODUCTS as $key => $spec) {
            $product = Product::query()
                ->where('organization_id', $organization->id)
                ->where('sku', $spec['sku'])
                ->first();

            if (! $product) {
                $product = app(CreateProductAction::class)->execute($organization, [
                    'sku' => $spec['sku'],
                    'internal_name' => $spec['name'],
                    'translations' => [['locale' => 'es-ES', 'name' => $spec['name']]],
                ]);
            }

            // Upserted on every run (not just at creation) so that adding a
            // new locale to EXTRA_LOCALE_NAMES later also reaches
            // installs that already have this demo seeded.
            $names = ['es-ES' => $spec['name']] + (self::EXTRA_LOCALE_NAMES['products'][$key] ?? []);
            foreach ($names as $locale => $name) {
                $product->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
            }

            $restaurantProduct = RestaurantProduct::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'product_id' => $product->id],
                ['price' => $spec['price'], 'available' => true],
            );

            $restaurantProduct->price = $spec['price'];
            $restaurantProduct->available = true;
            $restaurantProduct->save();

            $this->restaurantProductsByKey[$key] = $restaurantProduct;
            $this->productNamesByRestaurantProductId[$restaurantProduct->id] = $spec['name'];
        }

        $this->createMenuAndCategories($restaurant);
        $this->attachHamburguesaModifiers();
    }

    /**
     * Wires PRODUCTS into a single active Menu -> Category -> CategoryProduct
     * tree, without which BuildPublicMenuAction (the /public/.../menu
     * endpoint the QR client hits) has nothing to return — see
     * test_missing_menu_returns_menu_not_available in
     * tests/Feature/Public/PublicMenuTest.php for why a Menu is load-bearing,
     * not decorative.
     */
    private function createMenuAndCategories(Restaurant $restaurant): void
    {
        $menu = Menu::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            ['name' => 'Carta AFORO', 'status' => 'active'],
        );

        foreach (self::CATEGORIES as $key => $spec) {
            $category = Category::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'slug' => $key],
                ['sort_order' => $spec['sort_order'], 'status' => 'active'],
            );

            $names = ['es-ES' => $spec['name']] + (self::EXTRA_LOCALE_NAMES['categories'][$key] ?? []);
            foreach ($names as $locale => $name) {
                $category->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
            }

            foreach (array_values($spec['products']) as $sortOrder => $productKey) {
                CategoryProduct::query()->updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'restaurant_product_id' => $this->restaurantProductsByKey[$productKey]->id,
                    ],
                    ['sort_order' => $sortOrder],
                );
            }
        }
    }

    /**
     * Gives "Hamburguesa AFORO" a required single-choice group (Punto de la
     * carne) and an optional multi-choice one (Extras) — the two modifier
     * shapes the public ordering UI needs to prove out. Every other demo
     * product is left with none on purpose.
     */
    private function attachHamburguesaModifiers(): void
    {
        $restaurantProduct = $this->restaurantProductsByKey['hamburguesa'];

        foreach (self::HAMBURGUESA_MODIFIER_GROUPS as $groupSpec) {
            $group = ModifierGroup::query()->firstOrCreate(
                ['restaurant_product_id' => $restaurantProduct->id, 'internal_name' => $groupSpec['internal_name']],
                [
                    'min_select' => $groupSpec['min_select'],
                    'max_select' => $groupSpec['max_select'],
                    'required' => $groupSpec['required'],
                    'sort_order' => $groupSpec['sort_order'],
                    'status' => 'active',
                ],
            );

            foreach ($groupSpec['names'] as $locale => $name) {
                $group->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
            }

            foreach ($groupSpec['options'] as $optionSpec) {
                $option = ModifierOption::query()->firstOrCreate(
                    ['modifier_group_id' => $group->id, 'internal_name' => $optionSpec['names']['es-ES']],
                    [
                        'price_delta' => $optionSpec['price_delta'],
                        'available' => true,
                        'sort_order' => $optionSpec['sort_order'],
                        'status' => 'active',
                    ],
                );

                foreach ($optionSpec['names'] as $locale => $name) {
                    $option->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
                }
            }
        }
    }

    /**
     * Deletes only the demo restaurant's own operational rows, in FK-safe
     * order (children before the TableSession they belong to). Never a
     * global truncate, never another Restaurant's data — every query below
     * is scoped by this restaurant_id.
     */
    private function resetOperationalData(Restaurant $restaurant): void
    {
        // Cascades to order_items -> order_item_modifiers.
        Order::query()->where('restaurant_id', $restaurant->id)->delete();
        TableRequest::query()->where('restaurant_id', $restaurant->id)->delete();
        WaiterCall::query()->where('restaurant_id', $restaurant->id)->delete();
        PaymentRecord::query()->where('restaurant_id', $restaurant->id)->delete();
        StaffReview::query()->where('restaurant_id', $restaurant->id)->delete();
        StaffShift::query()->where('restaurant_id', $restaurant->id)->delete();
        TableSession::query()->where('restaurant_id', $restaurant->id)->delete();
    }

    /**
     * @param  array<int, Table>  $tables
     * @param  array<string, User>  $staff
     */
    private function seedHistoricalOperations(Restaurant $restaurant, Organization $organization, User $owner, array $tables, array $staff): void
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $tableList = array_values($tables);
        $waiterKeys = ['waiter1', 'waiter2', 'waiter3', 'waiter4'];
        $pairs = [[0, 1], [2, 3], [0, 2], [1, 3]];
        $guestCycle = [2, 3, 4, 2, 5, 3, 6, 4, 2, 3, 4, 5];
        $durationCycle = [40, 55, 70, 90, 50, 65];
        $prepCycle = [8, 11, 13, 17, 20];
        $methodCycle = [PaymentRecord::METHOD_CARD, PaymentRecord::METHOD_CASH];

        $globalSessionIndex = 0;
        $tableCursor = 0;

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $date = $today->subDays($daysAgo);
            $iso = $date->isoWeekday();
            $schedule = self::SCHEDULE[$iso];

            $onDutyKitchen = array_values(array_filter([
                $staff['kitchen1'],
                in_array($iso, [3, 5, 6, 7], true) ? $staff['kitchen2'] : null,
            ]));
            $onDutyCashier = in_array($iso, [4, 5, 6, 7], true) ? $staff['cashier'] : null;
            $pair = $pairs[$daysAgo % 4];
            $onDutyWaiters = [$staff[$waiterKeys[$pair[0]]], $staff[$waiterKeys[$pair[1]]]];

            // Eloquent's datetime cast formats a Carbon instance in
            // WHATEVER timezone it already carries — it does not convert
            // to UTC before storing. Every instant built from a local
            // (Europe/Madrid) wall-clock time must be explicitly ->utc()'d
            // before it touches a model attribute, exactly like
            // RestaurantClock does throughout the real application.
            $shiftStart = $date->setTime(12, 30)->utc();
            $shiftEnd = $date->setTime(23, 30)->utc();

            $onDutyToday = array_merge([$staff['manager']], $onDutyKitchen, $onDutyWaiters, $onDutyCashier ? [$onDutyCashier] : []);

            foreach ($onDutyToday as $dutyUser) {
                $this->createShift($restaurant, $dutyUser, $shiftStart, $shiftEnd, $staff['manager']);
            }

            foreach ($schedule['hours'] as $i => $hour) {
                $minute = ($i * 17) % 60;
                $openedAt = $date->setTime($hour, $minute)->utc();
                $table = $tableList[$tableCursor % count($tableList)];
                $tableCursor++;
                $guests = $guestCycle[$globalSessionIndex % count($guestCycle)];
                $duration = $durationCycle[$globalSessionIndex % count($durationCycle)];
                $waiter = $onDutyWaiters[$i % count($onDutyWaiters)];
                $kitchenUser = $onDutyKitchen[$i % count($onDutyKitchen)] ?? $staff['kitchen1'];

                $session = $this->openSession($restaurant, $table, $waiter, $waiter, $guests, $openedAt);

                $ordersCount = ($globalSessionIndex % 4 === 0) ? 2 : 1;

                for ($o = 0; $o < $ordersCount; $o++) {
                    $itemCount = 1 + (($globalSessionIndex + $o) % 3);
                    $items = $this->nextProducts($itemCount);
                    $orderCreatedAt = $openedAt->addMinutes(3 + $o * 6);
                    $prep = $prepCycle[$globalSessionIndex % count($prepCycle)];

                    $this->placeOrder(
                        $restaurant, $table, $session, $waiter, Order::ORIGIN_WAITER, $waiter,
                        $items, $orderCreatedAt, Order::STATUS_SERVED, $kitchenUser, $prep,
                    );
                }

                $closedAt = $openedAt->addMinutes($duration);
                $method = $methodCycle[$globalSessionIndex % count($methodCycle)];
                $this->payInFull($restaurant, $table, $session, $method, $closedAt->subMinute(), $waiter);
                $this->closeSession($session, $waiter, $closedAt);

                $globalSessionIndex++;
            }
        }

        $this->seedHistoricalReviews($organization, $restaurant, $owner, $staff, $today);
    }

    /**
     * @param  array<string, User>  $staff
     */
    private function seedHistoricalReviews(Organization $organization, Restaurant $restaurant, User $owner, array $staff, CarbonImmutable $today): void
    {
        $reviewDaysAgo = [42, 33, 24, 15, 6];

        // Five integer ratings per staff member, chosen so their average
        // lands close to a plausible target (e.g. Sofía ~4.8) — the
        // `rating` column itself is an integer 1-5, so any "4.7"-style
        // figure can only ever emerge as an AVG() across several reviews.
        $reviewRatings = [
            'waiter1' => [5, 5, 4, 5, 4],
            'waiter2' => [4, 4, 5, 4, 4],
            'waiter3' => [5, 5, 5, 4, 5],
            'waiter4' => [4, 5, 4, 4, 5],
            'kitchen1' => [5, 4, 5, 4, 5],
            'kitchen2' => [4, 4, 5, 5, 4],
            'cashier' => [4, 5, 4, 5, 4],
            'manager' => [5, 4, 5, 5, 4],
        ];

        foreach ($reviewRatings as $staffKey => $ratings) {
            $reviewer = $staffKey === 'manager' ? $owner : $staff['manager'];

            foreach ($ratings as $i => $rating) {
                $reviewDate = $today->subDays($reviewDaysAgo[$i])->setTime(10, 0)->utc();
                $this->createReview($organization, $restaurant, $staff[$staffKey], $reviewer, $rating, $reviewDate);
            }
        }
    }

    /**
     * @param  array<int, Table>  $tables
     * @param  array<string, User>  $staff
     */
    private function seedTodayOperations(Restaurant $restaurant, array $tables, array $staff): void
    {
        $anchor = CarbonImmutable::now();

        // A few lunch turns that already finished earlier today, on tables
        // that are free again now — this is what makes sales.received_today
        // (and today's slice of Analytics) non-zero without touching any
        // of the tables used for the still-open scenario below.
        $completedTurns = [
            ['table' => 1, 'waiter' => 'waiter1', 'guests' => 2, 'hoursAgo' => 5, 'durationMin' => 40, 'products' => ['croquetas', 'agua']],
            ['table' => 5, 'waiter' => 'waiter2', 'guests' => 3, 'hoursAgo' => 4, 'durationMin' => 55, 'products' => ['paella', 'cerveza', 'cocacola']],
            ['table' => 9, 'waiter' => 'waiter3', 'guests' => 4, 'hoursAgo' => 3, 'durationMin' => 50, 'products' => ['bravas', 'hamburguesa', 'vino', 'agua']],
        ];

        foreach ($completedTurns as $spec) {
            $table = $tables[$spec['table']];
            $waiter = $staff[$spec['waiter']];
            $openedAt = $anchor->subHours($spec['hoursAgo']);
            $closedAt = $openedAt->addMinutes($spec['durationMin']);
            $items = array_map(fn (string $key) => $this->restaurantProductsByKey[$key], $spec['products']);

            $session = $this->openSession($restaurant, $table, $waiter, $waiter, $spec['guests'], $openedAt);
            $this->placeOrder($restaurant, $table, $session, $waiter, Order::ORIGIN_WAITER, $waiter, $items, $openedAt->addMinutes(4), Order::STATUS_SERVED, $staff['kitchen1'], 10);
            $this->payInFull($restaurant, $table, $session, PaymentRecord::METHOD_CARD, $closedAt->subMinute(), $waiter);
            $this->closeSession($session, $waiter, $closedAt);
        }

        $this->seedCurrentOperations($restaurant, $tables, $staff, $anchor);
    }

    /**
     * @param  array<int, Table>  $tables
     * @param  array<string, User>  $staff
     */
    private function seedCurrentOperations(Restaurant $restaurant, array $tables, array $staff, CarbonImmutable $anchor): void
    {
        // Active staff shifts right now: manager, all 4 waiters and both
        // kitchen staff, plus the cashier — every waiter assigned to an
        // open session below is on an active shift, so the only
        // assignment-integrity alert produced is the deliberate one on
        // Mesa 02 (unassigned), not an accidental "off shift" one too.
        $this->createShift($restaurant, $staff['manager'], $anchor->subHours(6), null, $staff['manager']);
        $this->createShift($restaurant, $staff['waiter1'], $anchor->subHours(5), null, $staff['manager']);
        $this->createShift($restaurant, $staff['waiter2'], $anchor->subHours(5), null, $staff['manager']);
        $this->createShift($restaurant, $staff['waiter3'], $anchor->subHours(4), null, $staff['manager']);
        $this->createShift($restaurant, $staff['waiter4'], $anchor->subHours(4), null, $staff['manager']);
        $this->createShift($restaurant, $staff['kitchen1'], $anchor->subHours(6), null, $staff['manager']);
        $this->createShift($restaurant, $staff['kitchen2'], $anchor->subHours(3), null, $staff['manager']);
        $this->createShift($restaurant, $staff['cashier'], $anchor->subHours(4), null, $staff['manager']);

        // Mesa 02: no waiter assigned yet -> active_table_unassigned alert.
        $t02 = $tables[2];
        $s02 = $this->openSession($restaurant, $t02, $staff['manager'], null, 2, $anchor->subMinutes(25));
        $this->placeOrder($restaurant, $t02, $s02, $staff['manager'], Order::ORIGIN_WAITER, $staff['manager'], $this->nextProducts(2), $anchor->subMinutes(20), Order::STATUS_CONFIRMED);

        // Mesa 03: Lorena, a customer_qr order still waiting for approval.
        $t03 = $tables[3];
        $s03 = $this->openSession($restaurant, $t03, $staff['waiter1'], $staff['waiter1'], 4, $anchor->subMinutes(5));
        $this->placeOrder($restaurant, $t03, $s03, $staff['waiter1'], Order::ORIGIN_CUSTOMER_QR, null, $this->nextProducts(2), $anchor->subMinutes(2), Order::STATUS_WAITING_APPROVAL);

        // Mesa 04: Lorena, order accepted by the kitchen.
        $t04 = $tables[4];
        $s04 = $this->openSession($restaurant, $t04, $staff['waiter1'], $staff['waiter1'], 6, $anchor->subMinutes(45));
        $this->placeOrder($restaurant, $t04, $s04, $staff['waiter1'], Order::ORIGIN_WAITER, $staff['waiter1'], $this->nextProducts(3), $anchor->subMinutes(40), Order::STATUS_ACCEPTED, $staff['kitchen1']);

        // Mesa 06: Mateo, order just confirmed.
        $t06 = $tables[6];
        $s06 = $this->openSession($restaurant, $t06, $staff['waiter2'], $staff['waiter2'], 3, $anchor->subMinutes(12));
        $this->placeOrder($restaurant, $t06, $s06, $staff['waiter2'], Order::ORIGIN_WAITER, $staff['waiter2'], $this->nextProducts(2), $anchor->subMinutes(9), Order::STATUS_CONFIRMED);

        // Mesa 08: Mateo, two orders being prepared.
        $t08 = $tables[8];
        $s08 = $this->openSession($restaurant, $t08, $staff['waiter2'], $staff['waiter2'], 5, $anchor->subMinutes(60));
        $this->placeOrder($restaurant, $t08, $s08, $staff['waiter2'], Order::ORIGIN_WAITER, $staff['waiter2'], $this->nextProducts(2), $anchor->subMinutes(50), Order::STATUS_PREPARING, $staff['kitchen1'], 12);
        $this->placeOrder($restaurant, $t08, $s08, $staff['waiter2'], Order::ORIGIN_WAITER, $staff['waiter2'], $this->nextProducts(1), $anchor->subMinutes(30), Order::STATUS_PREPARING, $staff['kitchen2'], 9);

        // Mesa 10: Sofía, one order preparing, guest called the waiter.
        $t10 = $tables[10];
        $s10 = $this->openSession($restaurant, $t10, $staff['waiter3'], $staff['waiter3'], 4, $anchor->subMinutes(18));
        $this->placeOrder($restaurant, $t10, $s10, $staff['waiter3'], Order::ORIGIN_WAITER, $staff['waiter3'], $this->nextProducts(2), $anchor->subMinutes(14), Order::STATUS_PREPARING, $staff['kitchen1'], 11);
        $this->createTableRequest($restaurant, $t10, $s10, TableRequest::TYPE_CALL_WAITER, $anchor->subMinutes(6));

        // Mesa 13: Sofía, two orders ready to be served.
        $t13 = $tables[13];
        $s13 = $this->openSession($restaurant, $t13, $staff['waiter3'], $staff['waiter3'], 6, $anchor->subMinutes(35));
        $this->placeOrder($restaurant, $t13, $s13, $staff['waiter3'], Order::ORIGIN_WAITER, $staff['waiter3'], $this->nextProducts(3), $anchor->subMinutes(30), Order::STATUS_READY, $staff['kitchen2'], 13);
        $this->placeOrder($restaurant, $t13, $s13, $staff['waiter3'], Order::ORIGIN_WAITER, $staff['waiter3'], $this->nextProducts(2), $anchor->subMinutes(25), Order::STATUS_READY, $staff['kitchen1'], 8);

        // Mesa 14: Diego, guests already served and asking for the bill;
        // nobody has come, so the manager escalated with a responsible-
        // waiter call.
        $t14 = $tables[14];
        $s14 = $this->openSession($restaurant, $t14, $staff['waiter4'], $staff['waiter4'], 4, $anchor->subMinutes(90));
        $this->placeOrder($restaurant, $t14, $s14, $staff['waiter4'], Order::ORIGIN_WAITER, $staff['waiter4'], $this->nextProducts(3), $anchor->subMinutes(75), Order::STATUS_SERVED, $staff['kitchen1'], 15);
        $this->createTableRequest($restaurant, $t14, $s14, TableRequest::TYPE_REQUEST_BILL, $anchor->subMinutes(10));
        $this->createWaiterCall($restaurant, $s14, $staff['waiter4'], $staff['manager'], $anchor->subMinutes(6));

        // Mesa 01, 07, 09, 11, 12 (07/11/12 never touched today at all) are
        // deliberately left with no session — the "free tables" of the map.
    }

    // -- Direct, invariant-respecting record builders ------------------

    private function openSession(Restaurant $restaurant, Table $table, User $opener, ?User $waiter, int $guests, CarbonImmutable $openedAt): TableSession
    {
        return TableSession::query()->create([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'opened_by_user_id' => $opener->id,
            'guest_count' => $guests,
            'status' => 'occupied',
            'opened_at' => $openedAt,
            'assigned_waiter_user_id' => $waiter?->id,
        ]);
    }

    private function closeSession(TableSession $session, User $closer, CarbonImmutable $closedAt): void
    {
        $session->update([
            'status' => 'closed',
            'closed_at' => $closedAt,
            'closed_by_user_id' => $closer->id,
        ]);
    }

    private function payInFull(Restaurant $restaurant, Table $table, TableSession $session, string $method, CarbonImmutable $recordedAt, User $recordedBy): void
    {
        $balanceCents = SessionBillCalculator::summarize($session->fresh())['balanceCents'];

        if ($balanceCents <= 0) {
            return;
        }

        PaymentRecord::query()->create([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'table_session_id' => $session->id,
            'method' => $method,
            'amount' => Money::centsToDecimal($balanceCents),
            'currency' => PaymentRecord::CURRENCY_EUR,
            'recorded_by_user_id' => $recordedBy->id,
            'recorded_at' => $recordedAt,
        ]);

        $session->update(['payment_status' => TableSession::PAYMENT_STATUS_PAID, 'paid_at' => $recordedAt]);
    }

    /**
     * Creates an Order (with its OrderItems, priced from the current
     * catalog) and advances it up to $targetStatus in one pass. `created_at`
     * is backdated by assigning it before save() — Eloquent skips
     * re-stamping a timestamp column that is already dirty — so every
     * downstream Analytics query (which reads orders.created_at directly)
     * sees a coherent historical/near-real instant instead of "now".
     *
     * @param  array<int, RestaurantProduct>  $restaurantProducts
     */
    private function placeOrder(
        Restaurant $restaurant,
        Table $table,
        TableSession $session,
        User $waiter,
        string $origin,
        ?User $createdBy,
        array $restaurantProducts,
        CarbonImmutable $createdAt,
        string $targetStatus,
        ?User $kitchenUser = null,
        ?int $prepMinutes = null,
    ): Order {
        $subtotalCents = 0;
        $lines = [];

        foreach ($restaurantProducts as $restaurantProduct) {
            $unitCents = Money::decimalToCents((string) $restaurantProduct->price);
            $subtotalCents += $unitCents;
            $lines[] = ['product' => $restaurantProduct, 'unitCents' => $unitCents];
        }

        $initialStatus = $targetStatus === Order::STATUS_WAITING_APPROVAL
            ? Order::STATUS_WAITING_APPROVAL
            : Order::STATUS_CONFIRMED;

        $order = new Order([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'table_session_id' => $session->id,
            'origin' => $origin,
            'created_by_user_id' => $createdBy?->id,
            'status' => $initialStatus,
            'subtotal' => Money::centsToDecimal($subtotalCents),
            'modifiers_total' => '0.00',
            'total' => Money::centsToDecimal($subtotalCents),
        ]);
        $order->created_at = $createdAt;
        $order->updated_at = $createdAt;
        $order->save();

        foreach ($lines as $line) {
            $restaurantProduct = $line['product'];

            $order->items()->create([
                'restaurant_product_id' => $restaurantProduct->id,
                'product_id' => $restaurantProduct->product_id,
                'product_name_snapshot' => $this->productNamesByRestaurantProductId[$restaurantProduct->id],
                'unit_price_snapshot' => Money::centsToDecimal($line['unitCents']),
                'quantity' => 1,
                'modifiers_unit_total_snapshot' => '0.00',
                'unit_total_snapshot' => Money::centsToDecimal($line['unitCents']),
                'line_total_snapshot' => Money::centsToDecimal($line['unitCents']),
            ]);
        }

        $this->advanceOrder($order, $initialStatus, $targetStatus, $createdAt, $waiter, $kitchenUser, $prepMinutes);

        return $order->fresh();
    }

    private function advanceOrder(Order $order, string $fromStatus, string $targetStatus, CarbonImmutable $createdAt, User $waiter, ?User $kitchenUser, ?int $prepMinutes): void
    {
        if ($targetStatus === $fromStatus) {
            return;
        }

        $chain = [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED];
        $targetIndex = array_search($targetStatus, $chain, true);

        if ($targetIndex === false) {
            return;
        }

        $updates = [];
        $cursor = $createdAt;

        foreach (array_slice($chain, 0, $targetIndex + 1) as $step) {
            $cursor = match ($step) {
                Order::STATUS_ACCEPTED => $cursor->addMinutes(2),
                Order::STATUS_PREPARING => $cursor->addMinute(),
                Order::STATUS_READY => $cursor->addMinutes($prepMinutes ?? 12),
                Order::STATUS_SERVED => $cursor->addMinutes(4),
            };

            $actor = $step === Order::STATUS_SERVED ? $waiter : ($kitchenUser ?? $waiter);

            $updates["{$step}_by_user_id"] = $actor->id;
            $updates["{$step}_at"] = $cursor;
        }

        $updates['status'] = $targetStatus;
        $order->update($updates);
    }

    private function createTableRequest(Restaurant $restaurant, Table $table, TableSession $session, string $type, CarbonImmutable $createdAt): TableRequest
    {
        $request = new TableRequest([
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'table_session_id' => $session->id,
            'type' => $type,
            'status' => TableRequest::STATUS_PENDING,
        ]);
        $request->created_at = $createdAt;
        $request->updated_at = $createdAt;
        $request->save();

        return $request;
    }

    private function createWaiterCall(Restaurant $restaurant, TableSession $session, User $waiter, User $calledBy, CarbonImmutable $createdAt): WaiterCall
    {
        $call = new WaiterCall([
            'restaurant_id' => $restaurant->id,
            'table_session_id' => $session->id,
            'waiter_user_id' => $waiter->id,
            'called_by_user_id' => $calledBy->id,
            'status' => WaiterCall::STATUS_PENDING,
        ]);
        $call->created_at = $createdAt;
        $call->updated_at = $createdAt;
        $call->save();

        return $call;
    }

    private function createShift(Restaurant $restaurant, User $user, CarbonImmutable $startedAt, ?CarbonImmutable $endedAt, User $actor): StaffShift
    {
        return StaffShift::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'started_by_user_id' => $actor->id,
            'ended_by_user_id' => $endedAt ? $actor->id : null,
        ]);
    }

    private function createReview(Organization $organization, Restaurant $restaurant, User $staff, User $reviewer, int $rating, CarbonImmutable $at): void
    {
        $review = new StaffReview([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'staff_user_id' => $staff->id,
            'reviewer_user_id' => $reviewer->id,
            'rating' => $rating,
        ]);
        $review->created_at = $at;
        $review->updated_at = $at;
        $review->save();
    }

    /**
     * @return array<int, RestaurantProduct>
     */
    private function nextProducts(int $count): array
    {
        $picked = [];

        while (count($picked) < $count) {
            $key = self::POPULARITY_CYCLE[$this->productCursor % count(self::POPULARITY_CYCLE)];
            $this->productCursor++;
            $restaurantProduct = $this->restaurantProductsByKey[$key];

            if (! in_array($restaurantProduct, $picked, true)) {
                $picked[] = $restaurantProduct;
            }
        }

        return $picked;
    }
}
