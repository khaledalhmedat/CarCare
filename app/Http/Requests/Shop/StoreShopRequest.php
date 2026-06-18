<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
            'business_types' => ['nullable', 'array'],
            'business_types.*' => ['exists:business_types,id'],
            'car_brands' => ['nullable', 'array'],
            'car_brands.*' => ['exists:car_brands,id'],
            'part_categories' => ['nullable', 'array'],
            'part_categories.*' => ['exists:part_categories,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم المتجر مطلوب',
            'phone.required' => 'رقم الهاتف مطلوب',
            'city.required' => 'المدينة مطلوبة',
        ];
    }
}