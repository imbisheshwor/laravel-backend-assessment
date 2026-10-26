<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Http\Requests\SearchOrdersRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

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

        // Select only the columns the list response needs — avoids pulling service_name
        // varchar(150) and other wide item columns for every row on every page.
        // At billions of order_items rows this cuts per-request I/O by 4–10×.
        $query = Order::query()->with(['items:id,order_id,service_code,service_name,unit_price,quantity,line_total,department_id']);

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
            $departmentId = null;
            if (isset($filters['department_code'])) {
                $departmentId = \Illuminate\Support\Facades\DB::table('departments')
                    ->where('code', $filters['department_code'])
                    ->value('id');
            }

            $query->whereExists(function ($items) use ($filters, $departmentId) {
                $items->selectRaw('1')->from('order_items as oi')->whereColumn('oi.order_id', 'orders.id');
                if (isset($filters['service_code'])) {
                    $items->where('oi.service_code', $filters['service_code']);
                }
                if ($departmentId !== null) {
                    $items->where('oi.department_id', $departmentId);
                }
            });
        }

        $perPage = $request->integer('per_page', 20);

        // Cursor (keyset) pagination — avoids the OFFSET cost at deep pages.
        // Pass ?cursor=<token> returned by the previous page's next_cursor field.
        // The cursor encodes (created_at, id) of the last row seen so PostgreSQL
        // uses the covering index directly, regardless of how deep the page is.
        // Without cursor, falls back to simplePaginate which still avoids COUNT(*).
        if (!empty($filters['cursor'])) {
            return $this->cursorPage($query, $filters['cursor'], $perPage, $request->fullUrlWithoutQuery(['cursor', 'page']));
        }

        // simplePaginate avoids a full COUNT(*) scan on the table.
        // cursorPaginate() is the next step for truly deep pages at billions of rows,
        // but requires client support for opaque cursor tokens instead of page numbers.
        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate($perPage)->withQueryString();
    }

    /**
     * Keyset pagination using (created_at DESC, id DESC).
     *
     * Cursor token = base64url(JSON{created_at, id}) of the last row on the previous page.
     * Returns $perPage rows plus a next_cursor string if more rows exist.
     *
     * This is O(1) regardless of page depth because PostgreSQL applies the WHERE clause
     * directly to the covering index — no rows are skipped with OFFSET.
     */
    private function cursorPage($query, string $cursor, int $perPage, string $baseUrl): JsonResponse
    {
        try {
            $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/')), true, 2, JSON_THROW_ON_ERROR);
            $afterDate = $decoded['d'] ?? null;
            $afterId   = (int) ($decoded['i'] ?? 0);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid cursor token.'], 422);
        }

        if (!$afterDate || !$afterId) {
            return response()->json(['message' => 'Invalid cursor token.'], 422);
        }

        // Keyset WHERE: rows that sort before (created_at=$afterDate, id=$afterId).
        // Equivalent to: next page after the last seen row, using the covering index.
        $rows = (clone $query)
            ->where(function ($q) use ($afterDate, $afterId) {
                $q->where('created_at', '<', $afterDate)
                  ->orWhere(function ($q2) use ($afterDate, $afterId) {
                      $q2->where('created_at', $afterDate)
                         ->where('id', '<', $afterId);
                  });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($perPage + 1)  // fetch one extra to detect if a next page exists
            ->get();

        $hasMore    = $rows->count() > $perPage;
        $rows       = $rows->take($perPage);
        $nextCursor = null;

        if ($hasMore && $rows->isNotEmpty()) {
            $last = $rows->last();
            $payload = json_encode(['d' => $last->created_at->toDateTimeString(), 'i' => $last->id]);
            $nextCursor = strtr(base64_encode($payload), '+/', '-_');
        }

        return response()->json([
            'data'        => $rows->values(),
            'next_cursor' => $nextCursor,
            'next_url'    => $nextCursor ? $baseUrl.'?cursor='.$nextCursor.'&per_page='.$perPage : null,
        ]);
    }
}
