<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessOrderDepartments implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 60;
    public function __construct(public int $orderId) {}
    public function backoff(): array { return [1, 5, 15, 30]; }

    public function handle(): void
    {
        DB::transaction(function () {
            $outbox = DB::table('processing_outbox')->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (!$outbox || $outbox->processed_at !== null) {
                return;
            }
            if (!DB::table('orders')->where('id', $this->orderId)->where('status', 'paid')->exists()) {
                throw new \LogicException('Cannot process an unpaid order.');
            }
            $departments = DB::table('order_items')->where('order_id', $this->orderId)->distinct()->pluck('department_id');
            foreach ($departments as $departmentId) {
                DB::table('department_order_processings')->insertOrIgnore([
                    'order_id' => $this->orderId, 'department_id' => $departmentId,
                    'status' => 'handed_off', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('processing_outbox')->where('id', $outbox->id)->update(['processed_at' => now(), 'updated_at' => now()]);
        }, 5);
    }
}
