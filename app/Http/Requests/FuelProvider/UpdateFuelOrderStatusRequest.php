<?php

namespace App\Http\Requests\FuelProvider;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFuelOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:in_progress,completed'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'الحالة مطلوبة',
            'status.in' => 'الحالة غير صحيحة',
        ];
    }
}