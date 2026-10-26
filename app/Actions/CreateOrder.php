<?php

namespace App\Actions;

use App\Models\Order;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateOrder
{
    public function execute(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $codes = array_column($data['items'], 'service_code');
            // One joined statement locks all service AND department rows. FOR SHARE
            // conflicts with ordinary price/active UPDATEs, including direct SQL edits.
            $services = DB::table('services as s')->join('departments as d', 'd.id', '=', 's.department_id')
                ->whereIn('s.service_code', $codes)->orderBy('s.id')->orderBy('d.id')
                ->select('s.*', 'd.is_active as department_active')->lock('FOR SHARE OF s, d')->get()->keyBy('service_code');
            $errors = [];
            foreach ($data['items'] as $index => $item) {
                $service = $services->get($item['service_code']);
                if (!$service || !$service->is_active || !$service->department_active) {
                    $errors["items.$index.service_code"] = ['Service must exist and both service and department must be active.'];
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $total = 0;
            $items = [];
            foreach ($data['items'] as $item) {
                $service = $services[$item['service_code']];
                $line = Money::cents((string) $service->unit_price) * (int) $item['quantity'];
                $total += $line;
                $items[] = [
                    'service_id' => $service->id, 'department_id' => $service->department_id,
                    'service_code' => $service->service_code, 'service_name' => $service->name,
                    'unit_price' => $service->unit_price, 'quantity' => $item['quantity'],
                    'line_total' => Money::decimal($line), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $order = Order::create(['patient_id' => $data['patient_id'], 'total' => Money::decimal($total), 'status' => 'unpaid']);
            DB::table('order_items')->insert(array_map(fn ($item) => $item + ['order_id' => $order->id], $items));
            Audit::record('order.created', 'order', $order->id, null, ['status' => 'unpaid', 'total' => $order->total, 'items' => $items]);
            Audit::record('order.status_changed', 'order', $order->id, null, ['status' => 'unpaid']);
            return $order->load('items');
        }, 5);
    }
}
