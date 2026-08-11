<?php

namespace App\Http\Requests\Vehicle;

use App\Http\Requests\Vehicle\Concerns\NormalizesVehicleFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    use NormalizesVehicleFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeVehicleTextFields(['brand', 'model', 'plate_number']);
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', 'string', 'min:2', 'max:50', $this->brandModelFormatRule()],
            'model' => ['required', 'string', 'min:1', 'max:50', $this->brandModelFormatRule()],
            'year' => ['required', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'plate_number' => [
                'required',
                'string',
                $this->plateNumberFormatRule(),
                Rule::unique('vehicles')->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'current_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],

        ];
    }

    public function messages(): array
    {
        return [
            'brand.required' => 'العلامة التجارية مطلوبة',
            'brand.min' => 'العلامة التجارية قصيرة جداً',
            'model.required' => 'الموديل مطلوب',
            'year.required' => 'سنة الصنع مطلوبة',
            'year.min' => 'سنة الصنع يجب أن تكون 1900 أو أكثر',
            'year.max' => 'سنة الصنع غير صحيحة',
            'plate_number.required' => 'رقم اللوحة مطلوب',
            'plate_number.unique' => 'رقم اللوحة مستخدم بالفعل لدى إحدى مركباتك',
            'current_km.min' => 'المسافة المقطوعة لا يمكن أن تكون سالبة',
            'current_km.max' => 'المسافة المقطوعة غير منطقية',
            'image.image' => 'الملف يجب أن يكون صورة',
            'image.mimes' => 'صيغة الصورة غير مدعومة (jpeg, png, jpg, webp فقط)',
            'image.max' => 'حجم الصورة لا يتجاوز 5 ميجابايت',
        ];
    }
}
