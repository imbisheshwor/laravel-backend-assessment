<?php

namespace App\Actions;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveService
{
    public function execute(array $data, ?int $id = null): Service
    {
        return DB::transaction(function () use ($data, $id) {
            $service = $id ? Service::query()->lockForUpdate()->findOrFail($id) : new Service;
            $departmentId = $data['department_id'] ?? $service->department_id;
            $department = DB::table('departments')->where('id', $departmentId)->sharedLock()->first();
            // Permit deactivation even if its department has already been deactivated.
            $onlyDeactivating = count($data) === 1 && array_key_exists('is_active', $data) && !$data['is_active'];
            if (!$department || (!$department->is_active && !$onlyDeactivating)) {
                throw ValidationException::withMessages(['department_id' => 'Department must be active.']);
            }
            $service->fill($data)->save();
            return $service->refresh();
        }, 5);
    }
}
