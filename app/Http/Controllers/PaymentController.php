<?php

namespace App\Http\Controllers;

use App\Actions\PayOrder;
use App\Exceptions\SimulatedPaymentFailure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController
{
    public function store(Request $request, int $order, PayOrder $pay)
    {
        $key = Validator::make(['idempotency_key' => $request->header('Idempotency-Key')], [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ])->validate()['idempotency_key'];
        $request->validate(['simulate_failure' => ['sometimes', 'boolean']]);
        if (array_diff(array_keys($request->all()), ['simulate_failure'])) {
            return response()->json(['message' => 'Payment accepts only simulate_failure; amount is taken from the order.'], 422);
        }
        $simulate = $request->boolean('simulate_failure');
        if ($simulate && (!app()->environment('local', 'testing') || !config('payments.failure_simulation'))) {
            return response()->json(['message' => 'Payment failure simulation is disabled.'], 422);
        }
        try {
            $result = $pay->execute($order, $key, $simulate);
            return response()->json(['data' => $result['payment'], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
        } catch (SimulatedPaymentFailure $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
