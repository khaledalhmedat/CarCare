<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:in_progress,completed'],
            'completion_notes' => ['required_if:status,completed', 'string', 'max:1000'],
            'parts_used' => ['nullable', 'array'],
            'parts_used.*.name' => ['required_with:parts_used', 'string'],
            'parts_used.*.quantity' => ['required_with:parts_used', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'الحالة مطلوبة',
            'status.in' => 'الحالة غير صحيحة',
            'completion_notes.required_if' => 'ملاحظات الإنجاز مطلوبة',
            'parts_used.*.name.required_with' => 'اسم القطعة مطلوب',
            'parts_used.*.quantity.required_with' => 'الكمية مطلوبة',
        ];
    }
}