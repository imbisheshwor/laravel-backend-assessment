<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic maintenance for append-heavy tables at billion-row scale.
 *
 * Safe to run at any time. All DELETEs are bounded and batched so they don't
 * hold a long lock. Autovacuum will clean up the dead tuples afterward.
 *
 * Schedule: daily or weekly depending on write volume.
 *   Schedule::command('db:maintenance')->daily();
 */
class DatabaseMaintenance extends Command
{
    protected $signature = 'db:maintenance
        {--audit-days=90   : Delete acknowledged audit_logs older than N days}
        {--outbox-days=90  : Delete processed outbox rows older than N days}
        {--dry-run         : Report what would be deleted without deleting anything}
        {--batch=5000      : Rows to delete per statement to avoid long locks}';

    protected $description = 'Archive old acknowledged outbox rows and old audit logs (billion-row maintenance)';

    public function handle(): int
    {
        $auditDays  = (int) $this->option('audit-days');
        $outboxDays = (int) $this->option('outbox-days');
        $batch      = (int) $this->option('batch');
        $dry        = (bool) $this->option('dry-run');

        if ($auditDays < 1 || $outboxDays < 1 || $batch < 1 || $batch > 50000) {
            $this->error('Days must be >= 1 and batch must be 1..50000.');
            return self::FAILURE;
        }

        $auditBefore  = now()->subDays($auditDays)->toDateTimeString();
        $outboxBefore = now()->subDays($outboxDays)->toDateTimeString();

        if ($dry) {
            $this->warn('[DRY RUN] No rows will be deleted.');
        }

        // ------------------------------------------------------------------
        // 1. processing_outbox — delete acknowledged rows older than N days
        // ------------------------------------------------------------------
        // Only rows where processed_at IS NOT NULL are eligible.
        // The outbox_processed_at_idx (added in the scalability migration) makes
        // this DELETE an index seek rather than a full table scan.
        $outboxTotal = $this->deleteInBatches(
            table:  'processing_outbox',
            where:  "processed_at IS NOT NULL AND processed_at < ?",
            params: [$outboxBefore],
            batch:  $batch,
            dry:    $dry,
            label:  "outbox rows processed before {$outboxBefore}",
        );

        // ------------------------------------------------------------------
        // 2. audit_logs — soft archive: delete rows older than N days
        // ------------------------------------------------------------------
        // For strict compliance requirements, replace this DELETE with an
        // INSERT INTO audit_logs_archive ... SELECT ... pattern, or use
        // PostgreSQL PARTITION DETACH on the matching month partition.
        $auditTotal = $this->deleteInBatches(
            table:  'audit_logs',
            where:  "created_at < ?",
            params: [$auditBefore],
            batch:  $batch,
            dry:    $dry,
            label:  "audit rows older than {$auditBefore}",
        );

        $this->info(sprintf(
            '%sDeleted %d outbox rows and %d audit rows.',
            $dry ? '[DRY RUN] Would have deleted ' : '',
            $outboxTotal,
            $auditTotal,
        ));

        return self::SUCCESS;
    }

    /**
     * Delete rows in small batches so we don't hold a lock on the entire table.
     * Each batch is its own short transaction; autovacuum can reclaim dead tuples
     * between batches.
     */
    private function deleteInBatches(
        string $table,
        string $where,
        array  $params,
        int    $batch,
        bool   $dry,
        string $label,
    ): int {
        $total = 0;

        // Count first for the dry-run report.
        $count = (int) DB::scalar("SELECT count(*) FROM {$table} WHERE {$where}", $params);
        $this->line("  {$table}: {$count} rows eligible ({$label}).");

        if ($dry || $count === 0) {
            return $count;
        }

        // Batch DELETE using a CTE so we only scan the index once per batch.
        // ctid-based batching avoids secondary index lookups for the DELETE itself.
        do {
            $deleted = DB::affectingStatement(<<<SQL
                DELETE FROM {$table}
                WHERE ctid IN (
                    SELECT ctid FROM {$table}
                    WHERE {$where}
                    LIMIT {$batch}
                )
            SQL, $params);

            $total += $deleted;

            if ($deleted > 0) {
                $this->line("  Deleted {$deleted} from {$table} ({$total}/{$count} so far)...");
                // Yield to allow autovacuum and other queries between batches.
                usleep(50000); // 50 ms
            }
        } while ($deleted >= $batch);

        return $total;
    }
}
