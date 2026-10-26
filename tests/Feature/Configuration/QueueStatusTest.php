<?php

namespace Tests\Feature\Configuration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * aforo:queue-status reports counts/ages per queue, never payloads, and
 * never consumes or deletes anything.
 */
class QueueStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_and_age_without_payloads_and_without_touching_jobs(): void
    {
        config(['queue.default' => 'database', 'whatsapp.queue' => 'whatsapp', 'invitations.mail_queue' => 'mail', 'broadcasting.queue' => 'realtime']);
        $now = now()->getTimestamp();
        $job = fn (string $queue, int $createdAgo, int $availableIn = 0, ?int $reserved = null) => [
            'queue' => $queue, 'payload' => '{"secret":"PAYLOAD-MUST-NOT-PRINT +34612345678"}', 'attempts' => 0,
            'reserved_at' => $reserved, 'available_at' => $now + $availableIn, 'created_at' => $now - $createdAgo,
        ];
        DB::table('jobs')->insert([
            $job('whatsapp', 400), $job('whatsapp', 10), $job('whatsapp', 5, 300), $job('whatsapp', 20, 0, $now),
            $job('default', 1000),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'whatsapp',
            'payload' => '{"secret":"PAYLOAD-MUST-NOT-PRINT"}', 'exception' => 'EXCEPTION-MUST-NOT-PRINT', 'failed_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('aforo:queue-status'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s*whatsapp\s*\|\s*2\s*\|\s*1\s*\|\s*1\s*\|\s*400\s*\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s*default\s*\|\s*1\s*\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s*mail\s*\|\s*0\s*\|/', $output);
        $this->assertStringNotContainsString('PAYLOAD-MUST-NOT-PRINT', $output);
        $this->assertStringNotContainsString('EXCEPTION-MUST-NOT-PRINT', $output);
        $this->assertStringNotContainsString('612345678', $output);
        $this->assertSame(5, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->assertSame(1, Artisan::call('aforo:queue-status', ['--queue' => ['whatsapp'], '--max-age' => 300]));
        $this->assertSame(0, Artisan::call('aforo:queue-status', ['--queue' => ['whatsapp'], '--max-age' => 500]));
        $this->assertSame(0, Artisan::call('aforo:queue-status', ['--queue' => ['mail'], '--max-age' => 1]));
    }
}
