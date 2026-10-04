<?php

namespace Tests\Feature\Security;

use App\Actions\Catalog\StoreOrReplaceProductMediaAction;
use App\Models\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MediaWriteFailureTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_failed_replacement_preserves_the_previous_row_and_file(): void
    {
        $this->seedRolesAndPermissions();
        [$organization] = $this->createTenant();
        $product = $this->createProduct($organization);
        Storage::fake('public');
        config(['media.product_disk' => 'public']);
        $action = app(StoreOrReplaceProductMediaAction::class);
        $original = $action->execute($product, 'image', UploadedFile::fake()->image('old.jpg'));
        $path = $original->path;
        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('getMimeType')->andReturn('image/jpeg');
        $file->shouldReceive('storeAs')->once()->andReturn(false);
        $file->shouldReceive('getSize')->andReturn(100);

        try {
            $action->execute($product, 'image', $file);
            $this->fail('A false storage result must abort replacement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed to store product media.', $exception->getMessage());
        }

        $this->assertSame($path, $original->fresh()->path);
        $this->assertSame(1, ProductMedia::query()->where('product_id', $product->id)->count());
        Storage::disk('public')->assertExists($path);
    }
}
