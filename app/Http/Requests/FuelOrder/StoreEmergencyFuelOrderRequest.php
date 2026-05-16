<?php

namespace App\Http\Requests\FuelOrder;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmergencyFuelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'fuel_type' => ['required', 'in:95,98,diesel'],
            'amount' => ['required', 'numeric', 'min:1', 'max:200'],
            'delivery_latitude' => ['required', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['required', 'numeric', 'between:-180,180'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'المركبة مطلوبة',
            'fuel_type.required' => 'نوع الوقود مطلوب',
            'amount.required' => 'الكمية مطلوبة',
            'delivery_latitude.required' => 'الموقع مطلوب',
            'delivery_longitude.required' => 'الموقع مطلوب',
        ];
    }
}
