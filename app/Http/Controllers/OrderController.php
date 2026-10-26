<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Http\Requests\SearchOrdersRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Patient;
use Carbon\CarbonImmutable;

class OrderController
{
    public function store(StoreOrderRequest $request, CreateOrder $create)
    {
        return response()->json(['data' => $create->execute($request->validated())], 201);
    }

    public function index(SearchOrdersRequest $request)
    {
        return $this->search($request);
    }

    public function patientOrders(SearchOrdersRequest $request, int $patient)
    {
        Patient::findOrFail($patient);
        return $this->search($request, $patient);
    }

    private function search(SearchOrdersRequest $request, ?int $patientId = null)
    {
        $filters = $request->validated();
        $query = Order::query()->with('items');
        foreach (['status', 'patient_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($patientId !== null) {
            $query->where('patient_id', $patientId);
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->format('Y-m-d').' 00:00:00');
        }
        if (isset($filters['service_code']) || isset($filters['department_code'])) {
            $query->whereExists(function ($items) use ($filters) {
                $items->selectRaw('1')->from('order_items as oi')->whereColumn('oi.order_id', 'orders.id');
                if (isset($filters['service_code'])) {
                    $items->where('oi.service_code', $filters['service_code']);
                }
                if (isset($filters['department_code'])) {
                    $items->whereIn('oi.department_id', function ($departments) use ($filters) {
                        $departments->select('id')->from('departments')->where('code', $filters['department_code']);
                    });
                }
            });
        }
        // No full-table COUNT on 10M rows; one extra row determines next_page_url.
        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate($request->integer('per_page', 20))->withQueryString();
    }
}
