<?php

namespace Tests\Feature\Security;

use App\Models\RestaurantDayClose;
use App\Support\DayClose\Pdf\DayClosePdf;
use Illuminate\Filesystem\ServeFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPublicVisits;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SecurityCloseoutTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;
    use InteractsWithPublicVisits;

    public function test_staff_update_ignores_ownership_and_platform_privilege_injection(): void
    {
        $this->seedRolesAndPermissions();
        [$org, $owner, $restaurant] = $this->createTenant();
        [$foreign] = $this->createTenant();
        $staff = $this->createStaff($org, $restaurant, 'waiter', 'original');
        $roles = $staff->userRoles()->get()->toArray();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/staff/{$staff->id}", [
            'name' => 'Updated', 'organization_id' => $foreign->id, 'user_id' => $owner->id,
            'is_admin' => true, 'platform_role' => 'super_admin', 'role_id' => 1,
            'permission_id' => 1, 'restaurant_id' => 999999,
        ])->assertOk();
        $this->assertSame('Updated', $staff->fresh()->name);
        $this->assertSame($roles, $staff->userRoles()->get()->toArray());
        $this->assertFalse($staff->organizations()->whereKey($foreign->id)->exists());
        $this->assertSame([$restaurant->id], $staff->restaurants()->pluck('restaurants.id')->all());
        $this->actingAs($staff->fresh(), 'web')->getJson('/api/v1/platform/users')->assertForbidden();
    }

    public function test_accessible_parent_rejects_foreign_and_unrelated_nested_child_ids(): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $category = $this->createCategory($this->createMenu($restaurant));
        $category->categoryProducts()->create(['restaurant_product_id' => $rp->id]);
        [$foreign, , $foreignRestaurant, $foreignRp] = $this->createTenantWithRestaurantProduct();
        $foreignStaff = $this->createStaff($foreign, $foreignRestaurant, 'waiter', 'foreign');
        $this->actingAs($owner, 'web');
        $this->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$foreignStaff->id}/feedback-summary")->assertNotFound();
        $this->patchJson("/api/v1/categories/{$category->id}/products/{$foreignRp->id}", ['sort_order' => 99])->assertNotFound();
        $this->deleteJson("/api/v1/categories/{$category->id}/products/{$foreignRp->id}")->assertNotFound();
        $unrelated = $this->createCategory($restaurant->menu);
        $this->deleteJson("/api/v1/categories/{$unrelated->id}/products/{$rp->id}")->assertNotFound();
        $this->assertSame(1, $category->categoryProducts()->count());
        $this->assertSame('10.00', $foreignRp->fresh()->price);
    }

    public function test_public_order_key_bounds_and_body_key_injection(): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            'idempotency_key' => ['nested' => ['attacker']]];
        $this->postPublicJson($url, $payload, ['Idempotency-Key' => str_repeat('x', 101)])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->postPublicJson($url, $payload, ['Idempotency-Key' => '   '])->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('   ', $table->activeSession->orders()->firstOrFail()->idempotency_key);
    }

    public function test_pdf_snapshot_name_cannot_control_download_path_or_headers(): void
    {
        $pdf = app(DayClosePdf::class);
        foreach (["../../private/file\r\nX-Injected: true", '..\\secret.pdf', '%2e%2e%2fsecret', str_repeat('漢字-a', 100)] as $name) {
            $close = new RestaurantDayClose;
            $close->business_date = '2026-10-04';
            $close->report = ['summary' => ['restaurant' => ['name' => $name]]];
            $filename = $pdf->filename($close);
            $this->assertMatchesRegularExpression('/^aforo-cierre-diario-(?:[a-z0-9-]{1,40}-)?2026-10-04\.pdf$/', $filename);
        }
    }

    public function test_private_framework_file_http_response_rejects_unsigned_and_signed_traversal(): void
    {
        config(['app.debug' => false]);
        Storage::fake('local');
        Storage::disk('local')->put('safe.txt', 'safe fixture');
        // Ephemeral route invokes the installed framework handler unchanged.
        // Signing traversal in the test proves the storage boundary beyond HMAC.
        Route::get('/security-closeout-file/{path}', function (Request $request, string $path) {
            return (new ServeFile('local', ['visibility' => 'private'], true))($request, $path);
        })->where('path', '.*')->name('security-closeout-file');
        Route::getRoutes()->refreshNameLookups();
        $this->get('/security-closeout-file/safe.txt')->assertNotFound();
        $safe = URL::temporarySignedRoute('security-closeout-file', now()->addMinute(), ['path' => 'safe.txt'], false);
        $this->get($safe)->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        foreach (['../outside.txt', '..\\outside.txt', '%2e%2e/outside.txt', '%252e%252e/outside.txt'] as $path) {
            $url = URL::temporarySignedRoute('security-closeout-file', now()->addMinute(), ['path' => $path], false);
            $response = $this->get($url)->assertNotFound();
            $this->assertStringNotContainsString('PathTraversalDetected', $response->getContent());
            $this->assertStringNotContainsString(storage_path(), $response->getContent());
        }
    }
}
