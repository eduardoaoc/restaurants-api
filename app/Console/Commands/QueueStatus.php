<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Queue health without payloads: per queue, how many jobs are pending,
 * delayed and reserved, and the age of the oldest pending one; plus the
 * failed_jobs count per queue. Never reads `payload`/`exception` columns,
 * never consumes, deletes or retries anything.
 *
 *   php artisan aforo:queue-status
 *   php artisan aforo:queue-status --queue=whatsapp --max-age=300   # exit 1 if older
 *
 * Without --queue it reports the dedicated queues configured for this
 * install (BROADCAST_QUEUE, AFORO_MAIL_QUEUE, WHATSAPP_QUEUE) plus `default`.
 */
class QueueStatus extends Command
{
    protected $signature = 'aforo:queue-status
        {--queue=* : Queue name(s) to report (default: the configured ones + default)}
        {--max-age= : Exit 1 when the oldest pending job of any reported queue is older than this many seconds}';

    protected $description = 'Report queue sizes and ages without reading job payloads';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        $queues = $this->option('queue') ?: array_values(array_unique(array_filter([
            config('broadcasting.queue'), config('invitations.mail_queue'), config('whatsapp.queue'), 'default',
        ])));
        $maxAge = $this->option('max-age') !== null ? (int) $this->option('max-age') : null;
        $now = now()->getTimestamp();
        $rows = [];
        $stale = false;

        foreach ($queues as $queue) {
            if ($driver === 'database') {
                $table = (string) config("queue.connections.{$connection}.table", 'jobs');
                $stats = DB::connection(config("queue.connections.{$connection}.connection"))
                    ->table($table)
                    ->where('queue', $queue)
                    ->selectRaw('count(*) filter (where reserved_at is null and available_at <= ?) as pending', [$now])
                    ->selectRaw('count(*) filter (where reserved_at is null and available_at > ?) as delayed', [$now])
                    ->selectRaw('count(*) filter (where reserved_at is not null) as reserved')
                    ->selectRaw('min(created_at) filter (where reserved_at is null and available_at <= ?) as oldest', [$now])
                    ->first();
                $pending = (int) $stats->pending;
                $age = $stats->oldest !== null ? $now - (int) $stats->oldest : null;
                $rows[] = [$queue, $pending, (int) $stats->delayed, (int) $stats->reserved, $age ?? '-'];
            } else {
                // Generic drivers (redis): totals only.
                $pending = Queue::connection($connection)->size($queue);
                $age = null;
                $rows[] = [$queue, $pending, '-', '-', '-'];
            }

            if ($maxAge !== null && $age !== null && $age > $maxAge) {
                $stale = true;
            }
        }

        $this->line("connection: {$connection} ({$driver})");
        $this->table(['queue', 'pending', 'delayed', 'reserved', 'oldest pending (s)'], $rows);

        $failed = DB::connection(config('queue.failed.database'))
            ->table((string) config('queue.failed.table', 'failed_jobs'))
            ->select('queue', DB::raw('count(*) as total'))
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [$row->queue, (int) $row->total])
            ->all();
        $this->table(['failed_jobs queue', 'count'], $failed ?: [['-', 0]]);

        if ($stale) {
            $this->error("A reported queue has a pending job older than {$maxAge}s (is its worker running?).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
