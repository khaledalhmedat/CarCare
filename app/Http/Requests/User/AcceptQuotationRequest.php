<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class AcceptQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_date' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
