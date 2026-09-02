<?php

namespace App\Http\Requests\Api\V1\CategoryProduct;

use App\Models\Category;
use App\Models\RestaurantProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttachCategoryProductRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via CategoryPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = (int) $this->route('category');

        return [
            'restaurant_product_id' => [
                'required',
                'integer',
                Rule::exists('restaurant_products', 'id'),
                Rule::unique('category_products', 'restaurant_product_id')->where('category_id', $categoryId),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Ensure the restaurant product belongs to the same restaurant as the category,
     * so the client gets a clean 422 instead of relying solely on the model invariant.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $restaurantProductId = $this->input('restaurant_product_id');

            if (! $restaurantProductId) {
                return;
            }

            $category = Category::query()->with('menu')->find((int) $this->route('category'));
            $restaurantProduct = RestaurantProduct::query()->find($restaurantProductId);

            if ($category && $restaurantProduct && $category->menu->restaurant_id !== $restaurantProduct->restaurant_id) {
                $validator->errors()->add(
                    'restaurant_product_id',
                    'This product does not belong to the same restaurant as the category.'
                );
            }
        });
    }
}
