<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['female', 'male', 'other', 'unknown'])],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        return response()->json(['data' => Patient::create($data)], 201);
    }

    public function show(int $patient)
    {
        return response()->json(['data' => Patient::findOrFail($patient)]);
    }
}
