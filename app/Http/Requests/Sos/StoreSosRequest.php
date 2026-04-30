<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;

class StoreSosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'المركبة مطلوبة',
            'lat.required' => 'الموقع مطلوب',
            'lng.required' => 'الموقع مطلوب',
            'description.max' => 'الوصف لا يتجاوز 500 حرف',
        ];
    }
}