<?php

namespace App\Http\Requests\MaintenanceRequest;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'priority' => ['required', 'in:low,medium,high,emergency'],
            'preferred_date' => ['nullable', 'date', 'after:today'],
            
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'المركبة مطلوبة',
            'vehicle_id.exists' => 'المركبة غير موجودة',
            'description.required' => 'وصف المشكلة مطلوب',
            'description.min' => 'الوصف يجب أن لا يقل عن 10 أحرف',
            'priority.required' => 'الأولوية مطلوبة',
            'priority.in' => 'الأولوية يجب أن تكون: منخفضة، متوسطة، عالية، طارئة',
            
            'images.array' => 'الصور يجب أن تكون مصفوفة',
            'images.max' => 'يمكنك رفع 5 صور كحد أقصى',
            'images.*.image' => 'الملف يجب أن يكون صورة',
            'images.*.mimes' => 'الصورة يجب أن تكون jpeg, png, jpg',
            'images.*.max' => 'حجم الصورة لا يتجاوز 2 ميجابايت',
        ];
    }
}