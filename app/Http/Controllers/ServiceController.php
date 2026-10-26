<?php

namespace App\Http\Controllers;

use App\Actions\SaveService;
use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController
{
    public function store(ServiceRequest $request, SaveService $save)
    {
        return response()->json(['data' => $save->execute($request->validated())], 201);
    }

    public function update(ServiceRequest $request, int $service, SaveService $save)
    {
        return response()->json(['data' => $save->execute($request->validated(), $service)]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'department_code' => ['sometimes', 'string', 'max:32'], 'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        return Service::with('department')
            ->when($request->filled('department_code'), fn ($q) => $q->whereHas('department', fn ($d) => $d->where('code', $request->input('department_code'))))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString();
    }
}
