<?php

namespace App\Http\Requests\MaintenanceRequest;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'string', 'min:10', 'max:1000'],
            'priority' => ['sometimes', 'in:low,medium,high,emergency'],
            'preferred_date' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'الوصف يجب أن لا يقل عن 10 أحرف',
            'priority.in' => 'الأولوية يجب أن تكون: منخفضة، متوسطة، عالية، طارئة',
        ];
    }
}
