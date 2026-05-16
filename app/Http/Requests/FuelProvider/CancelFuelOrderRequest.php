<?php

namespace App\Http\Requests\FuelProvider;

use Illuminate\Foundation\Http\FormRequest;

class CancelFuelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}