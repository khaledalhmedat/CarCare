<?php

namespace App\Http\Requests\Sos;

use Illuminate\Foundation\Http\FormRequest;

class AcceptSosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estimated_arrival_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}