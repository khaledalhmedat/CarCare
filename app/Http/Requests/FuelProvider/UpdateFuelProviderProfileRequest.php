<?php

namespace App\Http\Requests\FuelProvider;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFuelProviderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'fuel_types' => ['nullable', 'array'],
            'prices' => ['nullable', 'array'],
            'prices.95' => ['nullable', 'numeric', 'min:0'],
            'prices.98' => ['nullable', 'numeric', 'min:0'],
            'prices.diesel' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'اسم الشركة مطلوب',
            'phone.required' => 'رقم الهاتف مطلوب',
            'city.required' => 'المدينة مطلوبة',
            'address.required' => 'العنوان مطلوب',
        ];
    }
}
