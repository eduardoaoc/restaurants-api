<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AdversarialCatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        Storage::fake('public');
    }

    public static function textPayloads(): array
    {
        return array_map(fn ($value) => [$value], ["'", '"', "' OR 1=1 --", '1); SELECT pg_sleep(1); --', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', "Unicode 漢字 \u{202E} RTL"]);
    }

    #[DataProvider('textPayloads')]
    public function test_catalog_text_is_persisted_as_data_and_ownership_injection_is_ignored(string $payload): void
    {
        [$organization, $owner] = $this->createTenant();
        [$foreign] = $this->createTenant();
        DB::enableQueryLog();
        $response = $this->actingAs($owner, 'web')->postJson('/api/v1/products', [
            'internal_name' => $payload,
            'organization_id' => $foreign->id, 'restaurant_id' => 999999, 'user_id' => $owner->id,
            'is_admin' => true, 'platform_role' => 'super_admin', 'role_id' => 1, 'permission_id' => 1,
            'translations' => [['locale' => 'en', 'name' => $payload, 'description' => $payload, 'product_id' => 999999]],
            'allergens' => [],
        ])->assertCreated();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $product = Product::query()->findOrFail($response->json('data.product.id'));
        $this->assertSame($organization->id, $product->organization_id);
        $this->assertSame($payload, $product->internal_name);
        $this->assertSame($payload, $product->translations()->firstOrFail()->description);
        $this->assertSame($product->id, $product->translations()->firstOrFail()->product_id);
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        foreach ($queries as $query) {
            if (strlen($payload) > 1) {
                $this->assertStringNotContainsString($payload, $query['query']);
            }
        }
        $this->actingAs($owner, 'web')->getJson('/api/v1/products?search='.rawurlencode($payload))->assertOk();
    }

    public function test_nested_floor_plan_metadata_cannot_move_a_table_or_replace_public_token(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, , $foreign] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $original = $table->public_token;
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurants/{$restaurant->id}/floor-plan/layout", ['tables' => [[
            'id' => $table->id, 'layout_x' => 0.5, 'restaurant_id' => $foreign->id,
            'public_token' => 'attacker-token', 'status' => 'inactive',
        ]]])->assertOk();
        $table->refresh();
        $this->assertSame($restaurant->id, $table->restaurant_id);
        $this->assertSame($original, $table->public_token);
        $this->assertSame('active', $table->status);
        $this->assertSame(0.5, $table->layout_x);
    }

    public static function disguisedFiles(): array
    {
        return [
            'fake JPEG' => ['photo.jpg', 'not an image'],
            'PHP disguised' => ['photo.php.jpg', '<?php echo "harmless"; ?>'],
            'HTML' => ['photo.jpg', '<html><script>alert(1)</script></html>'],
            'SVG' => ['photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'corrupt JPEG' => ['photo.jpg', "\xff\xd8\xff\xe0garbage"],
            'fake MP4' => ['clip.mp4', 'not a video'],
        ];
    }

    #[DataProvider('disguisedFiles')]
    public function test_real_file_content_is_validated(string $filename, string $content): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);
        $type = str_ends_with($filename, '.mp4') ? 'video' : 'image';
        $temporary = tmpfile();
        fwrite($temporary, $content);
        $file = new UploadedFile(stream_get_meta_data($temporary)['uri'], $filename, 'image/jpeg', null, true);
        $this->actingAs($owner, 'web')->post("/api/v1/products/{$product->id}/media/{$type}", ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('product_media', 0);
        fclose($temporary);
    }

    public static function filenames(): array
    {
        return array_map(fn ($name) => [$name], ['../../photo.php.jpg', '..\\photo.jpg', '%2e%2e%2fphoto.jpg', '%252e%252e%252fphoto.jpg', 'photo.php.jpg', str_repeat('a', 1000).'.jpg', '漢字-é.jpg']);
    }

    #[DataProvider('filenames')]
    public function test_client_filename_never_controls_storage_path(string $filename): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);
        $this->actingAs($owner, 'web')->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image($filename)])->assertOk();
        $path = $product->media()->firstOrFail()->path;
        $this->assertMatchesRegularExpression('~^products/'.$organization->id.'/'.$product->id.'/image/[0-9a-f-]{36}\.jpg$~', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_nested_product_types_and_moderate_depth_fail_validation_without_writes(): void
    {
        [, $owner] = $this->createTenant();
        $nested = 'value';
        for ($depth = 0; $depth < 20; $depth++) {
            $nested = ['nested' => $nested];
        }
        foreach ([['internal_name' => $nested], ['translations' => 'text'],
            ['translations' => [['locale' => ['en'], 'name' => ['bad'], 'description' => $nested]]],
            ['allergens' => [['nested']]]] as $override) {
            $this->actingAs($owner, 'web')->postJson('/api/v1/products', array_replace([
                'internal_name' => 'Valid', 'translations' => [['locale' => 'en', 'name' => 'Valid', 'description' => 'Valid']],
                'allergens' => [],
            ], $override))->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }
}
