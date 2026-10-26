<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOrderDepartments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=1000}';
    protected $description = 'Re-publish pending paid-order work until a worker acknowledges it';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 100000) {
            $this->error('limit must be between 1 and 100000');
            return self::FAILURE;
        }

        // cursor() streams rows one at a time — O(1) PHP memory regardless of how
        // many rows are pending. Critical when the queue worker is down for hours
        // and thousands of outbox rows accumulate.
        $count = 0;
        DB::table('processing_outbox')
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->cursor()
            ->each(function ($row) use (&$count) {
                ProcessOrderDepartments::dispatch($row->order_id)->afterCommit();
                $count++;
            });

        $this->info("Published {$count} pending orders. Re-publication is safe.");
        return self::SUCCESS;
    }
}
