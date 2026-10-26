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
        $rows = DB::table('processing_outbox')->whereNull('processed_at')->orderBy('id')->limit($limit)->get(['order_id']);
        foreach ($rows as $row) {
            ProcessOrderDepartments::dispatch($row->order_id)->afterCommit();
        }
        $this->info('Published '.$rows->count().' pending orders. Re-publication is safe.');
        return self::SUCCESS;
    }
}
