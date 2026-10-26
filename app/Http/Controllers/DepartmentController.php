<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController
{
    public function store(Request $request)
    {
        return response()->json(['data' => Department::create($request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:150'], 'is_active' => ['sometimes', 'required', 'boolean'],
        ]))], 201);
    }

    public function index(Request $request)
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        return Department::orderBy('id')->paginate($request->integer('per_page', 20));
    }

    public function update(Request $request, int $department)
    {
        $model = Department::findOrFail($department);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D', Rule::unique('departments', 'code')->ignore($model->id)],
            'name' => ['sometimes', 'required', 'string', 'max:150'], 'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        $model->update($data);
        return response()->json(['data' => $model->refresh()]);
    }
}
