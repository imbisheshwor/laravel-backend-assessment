<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchOrdersRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status'          => ['sometimes', Rule::in(['paid', 'unpaid'])],
            'patient_id'      => ['sometimes', 'integer', 'min:1'],
            'from'            => ['sometimes', 'date_format:Y-m-d'],
            'to'              => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'service_code'    => ['sometimes', 'string', 'max:32'],
            'department_code' => ['sometimes', 'string', 'max:32'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'            => ['sometimes', 'integer', 'min:1', 'max:100000'],
            // cursor — opaque token returned by the previous page's next_cursor field.
            // When present, page-number pagination is ignored and keyset pagination is used.
            // This avoids the OFFSET cost at deep pages against billions of rows.
            'cursor'          => ['sometimes', 'string', 'max:512'],
        ];
    }
}
