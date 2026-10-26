<?php

namespace App\Actions;

use App\Exceptions\SimulatedPaymentFailure;
use App\Jobs\ProcessOrderDepartments;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class PayOrder
{
    public function execute(int $orderId, string $key, bool $simulateFailure = false): array
    {
        Order::findOrFail($orderId);
        // Separate committed attempt survives a rollback of the business transaction.
        Audit::record('payment.attempted', 'order', $orderId);
        try {
            return DB::transaction(function () use ($orderId, $key, $simulateFailure) {
                // Global key serialization also handles a key reused across different orders.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
                $order = Order::query()->lockForUpdate()->findOrFail($orderId);
                $existing = Payment::where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->order_id !== $order->id) {
                        throw new HttpException(409, 'Idempotency key belongs to a different order.');
                    }
                    Audit::record('payment.replayed', 'order', $orderId, null, ['payment_id' => $existing->id]);
                    return ['payment' => $existing, 'replayed' => true];
                }
                if ($order->status === 'paid') {
                    throw new HttpException(409, 'Order is already paid with a different idempotency key.');
                }
                $payment = Payment::create(['order_id' => $orderId, 'idempotency_key' => $key, 'amount' => $order->total]);
                if ($simulateFailure) {
                    throw new SimulatedPaymentFailure('Simulated failure after payment insert.');
                }
                $order->update(['status' => 'paid', 'paid_at' => now()]);
                Audit::record('order.status_changed', 'order', $orderId, ['status' => 'unpaid'], ['status' => 'paid']);
                Audit::record('payment.succeeded', 'order', $orderId, null, ['payment_id' => $payment->id, 'amount' => $payment->amount]);
                DB::table('processing_outbox')->insert(['order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(function () use ($orderId) {
                    try {
                        ProcessOrderDepartments::dispatch($orderId)->afterCommit();
                    } catch (Throwable $e) {
                        // Commit already succeeded: the outbox publisher will recover this.
                        Log::error('After-commit dispatch failed; pending outbox retained.', ['order_id' => $orderId, 'exception' => $e->getMessage()]);
                    }
                });
                return ['payment' => $payment->refresh(), 'replayed' => false];
            }, 5);
        } catch (Throwable $e) {
            Audit::record('payment.failed', 'order', $orderId, null, ['reason' => $e instanceof HttpException ? $e->getMessage() : 'Transaction rolled back.']);
            throw $e;
        }
    }
}
