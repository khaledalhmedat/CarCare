<?php

namespace App\Http\Requests\Admin;

use App\Models\Advertisement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdvertisementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route already guarded by auth:sanctum + role:admin
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            // image optional on update; when present it replaces the current one
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'placement' => ['nullable', Rule::in(Advertisement::PLACEMENTS)],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.image' => 'يجب أن يكون الملف صورة صالحة',
            'image.mimes' => 'صيغة الصورة يجب أن تكون jpeg أو jpg أو png أو webp',
            'image.max' => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت',
            'link_url.url' => 'الرابط غير صالح',
        ];
    }
}
