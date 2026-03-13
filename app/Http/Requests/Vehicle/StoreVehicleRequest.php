<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles'],
            'current_km' => ['nullable', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],

        ];
    }

    public function messages(): array
    {
        return [
            'brand.required' => 'العلامة التجارية مطلوبة',
            'model.required' => 'الموديل مطلوب',
            'year.required' => 'سنة الصنع مطلوبة',
            'year.min' => 'سنة الصنع يجب أن تكون 1900 أو أكثر',
            'year.max' => 'سنة الصنع غير صحيحة',
            'plate_number.required' => 'رقم اللوحة مطلوب',
            'plate_number.unique' => 'رقم اللوحة مستخدم بالفعل',
            'image.image' => 'الملف يجب أن يكون صورة',
            'image.mimes' => 'الصورة يجب أن تكون jpeg, png, jpg',
            'image.max' => 'حجم الصورة لا يتجاوز 2 ميجابايت',
        ];
    }
}
