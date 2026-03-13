<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTechnicianProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialization' => ['sometimes', 'string', 'max:255'],
            'experience_years' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'city' => ['sometimes', 'string', 'max:100'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            
            'certifications' => ['nullable', 'array', 'max:5'], 
            'certifications.*' => ['file', 'mimes:pdf,jpeg,png,jpg', 'max:2048'], 
        ];
    }

    public function messages(): array
    {
        return [
            'specialization.string' => 'التخصص يجب أن يكون نصاً',
            'experience_years.integer' => 'سنوات الخبرة يجب أن تكون رقماً',
            'hourly_rate.numeric' => 'سعر الساعة يجب أن يكون رقماً',
            
            'certifications.array' => 'الشهادات يجب أن تكون مصفوفة',
            'certifications.max' => 'يمكنك رفع 5 شهادات كحد أقصى',
            'certifications.*.file' => 'كل شهادة يجب أن تكون ملفاً',
            'certifications.*.mimes' => 'الملفات المسموحة: pdf, jpeg, png, jpg',
            'certifications.*.max' => 'حجم الملف لا يتجاوز 2 ميجابايت',
        ];
    }
}