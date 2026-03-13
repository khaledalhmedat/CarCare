<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'plate_number' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('vehicles')->ignore($this->route('vehicle'))
            ],
            'current_km' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],

        ];
    }

    public function messages(): array
    {
        return [
            'plate_number.unique' => 'رقم اللوحة مستخدم بالفعل',
        ];
    }
}
