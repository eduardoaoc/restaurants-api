<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Regression coverage for the public QR surface being misclassified as a
 * Sanctum "frontend" request. bootstrap/app.php's statefulApi() injects
 * Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful into the
 * whole `api` middleware group. That middleware treats any request whose
 * Origin/Referer matches config('sanctum.stateful') — which includes the
 * SPA's own dev origin, e.g. http://localhost:5174 — as coming from the
 * first-party frontend, and pipes it through StartSession + CSRF
 * validation. The public QR client never receives a session cookie or an
 * XSRF-TOKEN, so every state-changing public request made by a real browser
 * tab (which always sends Origin) used to fail with 419 "CSRF token
 * mismatch", while the exact same request via curl (no Origin/Referer)
 * passed straight through. routes/api.php now excludes that middleware
 * from the whole `public/*` group, which must keep this surface genuinely
 * stateless regardless of Origin.
 */
class PublicCsrfOriginTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_public_order_creation_does_not_419_when_called_from_the_spa_origin(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this
            ->withHeaders(['Origin' => self::SPA_ORIGIN])
            ->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ]);

        $response->assertStatus(201);
        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertDatabaseCount('orders', 1);

        // Genuinely stateless: no session cookie handed to an anonymous
        // QR client, even though the request carried a known SPA Origin.
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_public_call_waiter_does_not_419_when_called_from_the_spa_origin(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this
            ->withHeaders(['Origin' => self::SPA_ORIGIN])
            ->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter");

        $response->assertStatus(201);
        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_public_bill_request_does_not_419_when_called_from_the_spa_origin(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this
            ->withHeaders(['Origin' => self::SPA_ORIGIN])
            ->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill");

        $response->assertStatus(201);
        $this->assertNotSame(419, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_public_menu_get_is_unaffected_by_spa_origin(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this
            ->withHeaders(['Origin' => self::SPA_ORIGIN])
            ->getJson("/api/v1/public/tables/{$table->public_token}");

        $response->assertStatus(200);
        $this->assertNotSame(419, $response->getStatusCode());
    }
}
