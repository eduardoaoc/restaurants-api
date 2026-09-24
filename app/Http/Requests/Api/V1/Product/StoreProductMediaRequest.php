<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Models\ProductMedia;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductMediaRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via ProductPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules depend on the `{type}` route segment — the route itself
     * already constrains it to image|video (see routes/api.php), so
     * `$type` here is always one of ProductMedia::TYPES.
     *
     * `mimetypes` is content-sniffed (via PHP's fileinfo) rather than
     * trusting the client-supplied Content-Type or the filename extension
     * — this is what actually keeps a renamed .exe from passing as a jpg.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->route('type');

        $maxKilobytes = $type === ProductMedia::TYPE_VIDEO
            ? ProductMedia::MAX_VIDEO_KILOBYTES
            : ProductMedia::MAX_IMAGE_KILOBYTES;

        $mimeTypes = implode(',', array_keys(ProductMedia::ACCEPTED_MIME_TYPES[$type] ?? []));

        return [
            'file' => ['required', 'file', "mimetypes:{$mimeTypes}", "max:{$maxKilobytes}"],
        ];
    }
}
