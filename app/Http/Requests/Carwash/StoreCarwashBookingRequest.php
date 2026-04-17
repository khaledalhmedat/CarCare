<?php

namespace App\Http\Requests\Carwash;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarwashBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'car_washer_id' => ['required', 'exists:car_washers,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'service_type' => ['required', 'in:basic,premium,vip'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'المركبة مطلوبة',
            'car_washer_id.required' => 'المغسلة مطلوبة',
            'car_washer_id.exists' => 'المغسلة غير موجودة',
            'scheduled_at.required' => 'موعد الغسيل مطلوب',
            'scheduled_at.after' => 'الموعد يجب أن يكون بعد الوقت الحالي',
            'service_type.required' => 'نوع الخدمة مطلوب',
            'service_type.in' => 'نوع الخدمة غير صحيح',
        ];
    }
}