<?php

namespace App\Http\Requests\FuelOrder;

use Illuminate\Foundation\Http\FormRequest;

class StoreFuelOrderRequest extends FormRequest
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
            'delivery_address' => ['required', 'string', 'max:500'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'scheduled_time' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'المركبة مطلوبة',
            'fuel_type.required' => 'نوع الوقود مطلوب',
            'fuel_type.in' => 'نوع الوقود غير صحيح',
            'amount.required' => 'كمية الوقود مطلوبة',
            'amount.min' => 'الكمية يجب أن تكون لتر واحد على الأقل',
            'amount.max' => 'الكمية لا تتجاوز 200 لتر',
            'delivery_address.required' => 'عنوان التوصيل مطلوب',
        ];
    }
}