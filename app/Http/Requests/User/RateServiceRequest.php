<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class RateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'التقييم مطلوب',
            'rating.min' => 'التقييم يجب أن يكون بين 1 و 5',
            'rating.max' => 'التقييم يجب أن يكون بين 1 و 5',
        ];
    }
}
