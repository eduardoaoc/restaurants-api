<?php

namespace Tests\Feature\Product;

use App\Models\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Carta 4.3 — product image/video upload, replace, delete and their
 * exposure through ProductResource/PublicProductResource.
 */
class ProductMediaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        Storage::fake('public');
    }

    // --- image validation -------------------------------------------------

    public function test_uploading_a_valid_jpeg_creates_the_image_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $response = $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertOk();

        $this->assertSame('image', $response->json('data.media.type'));
        $this->assertSame('image/jpeg', $response->json('data.media.mime_type'));
        $this->assertSame(1, ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->count());
    }

    public function test_uploading_a_valid_png_creates_the_image_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->image('photo.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.media.mime_type', 'image/png');
    }

    public function test_uploading_a_valid_webp_creates_the_image_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->image('photo.webp'),
            ])
            ->assertOk()
            ->assertJsonPath('data.media.mime_type', 'image/webp');
    }

    public function test_uploading_a_non_image_file_to_the_image_slot_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_uploading_an_svg_to_the_image_slot_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_uploading_an_image_over_the_size_limit_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->image('photo.jpg')->size(ProductMedia::MAX_IMAGE_KILOBYTES + 1),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_image_upload_response_never_exposes_disk_or_path(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $response = $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['id', 'type', 'url', 'mime_type', 'size_bytes'],
            array_keys($response->json('data.media'))
        );

        $media = ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->firstOrFail();
        Storage::disk('public')->assertExists($media->path);
        $this->assertStringStartsWith("products/{$organization->id}/{$product->id}/image/", $media->path);
    }

    // --- video validation -------------------------------------------------

    public function test_uploading_a_valid_mp4_creates_the_video_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", [
                'file' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.media.type', 'video')
            ->assertJsonPath('data.media.mime_type', 'video/mp4');
    }

    public function test_uploading_a_valid_webm_creates_the_video_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", [
                'file' => UploadedFile::fake()->create('clip.webm', 2048, 'video/webm'),
            ])
            ->assertOk()
            ->assertJsonPath('data.media.mime_type', 'video/webm');
    }

    public function test_uploading_an_unsupported_video_type_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", [
                'file' => UploadedFile::fake()->create('clip.mov', 2048, 'video/quicktime'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    /**
     * A file merely named ".mp4" with real (content-sniffed) MIME content
     * that is NOT video/mp4|webm must still be rejected — proving the rule
     * checks the file's actual type, not its extension.
     */
    public function test_video_validation_does_not_trust_the_filename_extension(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", [
                'file' => UploadedFile::fake()->create('disguised.mp4', 2048, 'video/quicktime'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_uploading_a_video_over_the_size_limit_is_rejected(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", [
                'file' => UploadedFile::fake()->create('clip.mp4', ProductMedia::MAX_VIDEO_KILOBYTES + 1, 'video/mp4'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    // --- replace ------------------------------------------------------

    public function test_uploading_a_new_image_replaces_the_previous_one(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk();
        $firstPath = ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->firstOrFail()->path;

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('b.png')])
            ->assertOk()
            ->assertJsonPath('data.media.mime_type', 'image/png');

        $this->assertSame(1, ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->count());
        $secondPath = ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->firstOrFail()->path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_replacing_the_image_does_not_touch_the_video_slot(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", ['file' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4')])
            ->assertOk();
        $videoId = ProductMedia::query()->where('product_id', $product->id)->where('type', 'video')->value('id');

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk();
        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('b.jpg')])
            ->assertOk();

        $this->assertSame($videoId, ProductMedia::query()->where('product_id', $product->id)->where('type', 'video')->value('id'));
        $this->assertSame(1, ProductMedia::query()->where('product_id', $product->id)->where('type', 'video')->count());
    }

    // --- delete ---------------------------------------------------------

    public function test_deleting_the_image_clears_the_slot_and_preserves_the_video(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk();
        $imagePath = ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->firstOrFail()->path;

        $this->actingAs($owner, 'web')
            ->post("/api/v1/products/{$product->id}/media/video", ['file' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4')])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->delete("/api/v1/products/{$product->id}/media/image")
            ->assertNoContent();

        $this->assertSame(0, ProductMedia::query()->where('product_id', $product->id)->where('type', 'image')->count());
        $this->assertSame(1, ProductMedia::query()->where('product_id', $product->id)->where('type', 'video')->count());
        Storage::disk('public')->assertMissing($imagePath);

        $show = $this->actingAs($owner, 'web')->getJson("/api/v1/products/{$product->id}")->assertOk();
        $this->assertNull($show->json('data.product.media.image'));
        $this->assertNotNull($show->json('data.product.media.video'));
    }

    public function test_deleting_an_already_empty_slot_is_idempotent(): void
    {
        [$organization, $owner] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->delete("/api/v1/products/{$product->id}/media/video")
            ->assertNoContent();
    }

    // --- authorization / tenant scope ------------------------------------

    public function test_user_without_manage_products_permission_cannot_upload_media(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertForbidden();
    }

    public function test_user_without_manage_products_permission_cannot_delete_media(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->delete("/api/v1/products/{$product->id}/media/image")
            ->assertForbidden();
    }

    public function test_uploading_media_to_a_product_of_another_organization_is_not_found(): void
    {
        [$organizationA, $ownerA] = $this->createTenant();
        $productB = $this->createProduct($this->createTenant()[0]);

        $this->actingAs($ownerA, 'web')
            ->post("/api/v1/products/{$productB->id}/media/image", ['file' => UploadedFile::fake()->image('a.jpg')])
            ->assertNotFound();
    }

    public function test_deleting_media_of_a_product_of_another_organization_is_not_found(): void
    {
        [$organizationA, $ownerA] = $this->createTenant();
        $productB = $this->createProduct($this->createTenant()[0]);

        $this->actingAs($ownerA, 'web')
            ->delete("/api/v1/products/{$productB->id}/media/image")
            ->assertNotFound();
    }

    // --- ProductResource shape -------------------------------------------

    public function test_product_index_and_show_do_not_n_plus_one_on_media(): void
    {
        [$organization, $owner] = $this->createTenant();
        foreach (range(1, 5) as $i) {
            $product = $this->createProduct($organization, "Product {$i}");
            $this->actingAs($owner, 'web')
                ->post("/api/v1/products/{$product->id}/media/image", ['file' => UploadedFile::fake()->image("photo{$i}.jpg")])
                ->assertOk();
        }

        DB::enableQueryLog();
        $this->actingAs($owner, 'web')->getJson('/api/v1/products')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A handful of fixed queries (auth/tenant resolution, products,
        // translations, media) — NOT one extra query per product.
        $this->assertLessThan(10, $queryCount);
    }
}
