<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'total' => ['missing'], 'unit_price' => ['missing'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:service_code,quantity'],
            'items.*.service_code' => ['required', 'string', 'max:32', 'distinct:strict'],
            'items.*.quantity' => ['bail', 'required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (!is_int($value)) {
                    $fail('The :attribute must be a JSON integer.');
                }
            }, 'integer', 'min:1', 'max:10'],
            'items.*.unit_price' => ['missing'],
        ];
    }
}
