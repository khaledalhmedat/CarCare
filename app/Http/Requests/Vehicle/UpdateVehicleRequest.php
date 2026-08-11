<?php

namespace App\Http\Requests\Vehicle;

use App\Http\Requests\Vehicle\Concerns\NormalizesVehicleFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
            'brand' => ['sometimes', 'string', 'min:2', 'max:50', $this->brandModelFormatRule()],
            'model' => ['sometimes', 'string', 'min:1', 'max:50', $this->brandModelFormatRule()],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'plate_number' => [
                'sometimes',
                'string',
                $this->plateNumberFormatRule(),
                // ملاحظة: مفتاح المسار الفعلي هو "id" (Route::put('/{id}', ...))، وليس "vehicle" —
                // استخدام الاسم الخاطئ سابقاً كان يمنع ignore() من استبعاد الصف الحالي فعلياً.
                Rule::unique('vehicles')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id))
                    ->ignore($this->route('id')),
            ],
            'current_km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000000'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],

        ];
    }

    public function messages(): array
    {
        return [
            'brand.min' => 'العلامة التجارية قصيرة جداً',
            'year.min' => 'سنة الصنع يجب أن تكون 1900 أو أكثر',
            'year.max' => 'سنة الصنع غير صحيحة',
            'plate_number.unique' => 'رقم اللوحة مستخدم بالفعل لدى إحدى مركباتك',
            'current_km.min' => 'المسافة المقطوعة لا يمكن أن تكون سالبة',
            'current_km.max' => 'المسافة المقطوعة غير منطقية',
            'image.image' => 'الملف يجب أن يكون صورة',
            'image.mimes' => 'صيغة الصورة غير مدعومة (jpeg, png, jpg, webp فقط)',
            'image.max' => 'حجم الصورة لا يتجاوز 5 ميجابايت',
        ];
    }
}
