<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'city' => ['sometimes', 'string', 'max:100'],
            'business_types' => ['nullable', 'array'],
            'business_types.*' => ['exists:business_types,id'],
            'car_brands' => ['nullable', 'array'],
            'car_brands.*' => ['exists:car_brands,id'],
            'part_categories' => ['nullable', 'array'],
            'part_categories.*' => ['exists:part_categories,id'],
        ];
    }
}