<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        return [
            'service_code' => [$presence, 'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D', Rule::unique('services', 'service_code')->ignore($this->route('service'))],
            'name' => [$presence, 'required', 'string', 'max:150'],
            'department_id' => [$presence, 'required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            // Decimal strings avoid an ambiguous binary floating point price input.
            'unit_price' => [$presence, 'required', 'string', 'regex:/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/D', 'numeric', 'gt:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
