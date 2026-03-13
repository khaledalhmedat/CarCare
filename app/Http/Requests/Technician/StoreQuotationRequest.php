<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'min:1'],
            'estimated_days' => ['required', 'integer', 'min:1', 'max:30'], 
            'notes' => ['nullable', 'string', 'max:500'],
            'parts_included' => ['nullable', 'boolean'],  
        ];
    }

    public function messages(): array
    {
        return [
            'price.required' => 'السعر مطلوب',
            'price.numeric' => 'السعر يجب أن يكون رقماً',
            'price.min' => 'السعر يجب أن يكون 1 على الأقل',
            'estimated_days.required' => 'عدد الأيام التقديرية مطلوب',  
            'estimated_days.min' => 'عدد الأيام يجب أن يكون 1 على الأقل',
            'notes.max' => 'الملاحظات لا تتجاوز 500 حرف',
        ];
    }
}