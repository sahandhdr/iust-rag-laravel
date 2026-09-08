<?php

namespace App\Jobs;

use App\Models\Log\Log as AuditLog;
use App\Services\RagResponseCache\RagResponseCache;
use App\Utility\PythonDocumentSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 1 minimal queue: re-embed all published documents into Qdrant.
 * Uses PythonDocumentSync (same logic as former sync reembed endpoint).
 * Single-flight via Cache lock rag:reembed.
 */
class ReembedPublishedDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long-running: many documents × ingest timeout */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly string $jobKey,
        public readonly ?int $triggeredByUserId = null
    ) {
    }

    public function handle(): void
    {
        $lock = Cache::lock('rag:reembed', 3600);

        if (!$lock->get()) {
            $this->writeStatus([
                'status'  => 'skipped',
                'reason'  => 'already-running',
                'job_key' => $this->jobKey,
            ]);
            Log::warning('ReembedPublishedDocumentsJob skipped: lock held', [
                'job_key' => $this->jobKey,
            ]);
            return;
        }

        $this->writeStatus([
            'status'    => 'running',
            'job_key'   => $this->jobKey,
            'started_at'=> now()->toIso8601String(),
        ]);

        try {
            $sync = new PythonDocumentSync();
            $report = $sync->reembedAllPublished();

            try {
                (new RagResponseCache())->invalidateAll();
            } catch (Throwable $cacheEx) {
                Log::warning('Reembed cache invalidate failed', [
                    'error' => $cacheEx->getMessage(),
                ]);
            }

            $timeoutCount = (int) ($report['timeout_count'] ?? 0);
            $fail = (int) ($report['fail'] ?? 0);

            $message = 'reembed-finished';
            if ($timeoutCount > 0) {
                $message = 'reembed-partial-timeout';
            } elseif ($fail > 0) {
                $message = 'reembed-partial-failed';
            }

            $payload = [
                'status'     => $fail > 0 ? 'partial' : 'finished',
                'message'    => $message,
                'job_key'    => $this->jobKey,
                'finished_at'=> now()->toIso8601String(),
                'report'     => $report,
            ];

            $this->writeStatus($payload);
            $this->auditSafe('rag.reembed_finished', $payload);

            Log::info('ReembedPublishedDocumentsJob finished', [
                'job_key' => $this->jobKey,
                'total'   => $report['total'] ?? 0,
                'ok'      => $report['ok'] ?? 0,
                'fail'    => $fail,
            ]);
        } catch (Throwable $e) {
            $payload = [
                'status'     => 'failed',
                'message'    => 'reembed-job-failed',
                'job_key'    => $this->jobKey,
                'error'      => $e->getMessage(),
                'finished_at'=> now()->toIso8601String(),
            ];
            $this->writeStatus($payload);
            $this->auditSafe('rag.reembed_failed', $payload);

            Log::error('ReembedPublishedDocumentsJob failed', [
                'job_key' => $this->jobKey,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->writeStatus([
            'status'     => 'failed',
            'message'    => 'reembed-job-failed',
            'job_key'    => $this->jobKey,
            'error'      => $e?->getMessage(),
            'finished_at'=> now()->toIso8601String(),
        ]);
    }

    private function writeStatus(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put('rag:reembed:status:' . $this->jobKey, $data, now()->addDay());
        Cache::put('rag:reembed:last', $data, now()->addDay());
    }

    private function auditSafe(string $action, array $details): void
    {
        try {
            $log = new AuditLog();
            $log->action = $action;
            $log->entity_type = 'rag_index';
            $log->entity_id = $this->jobKey;
            $log->details = array_merge($details, [
                'triggered_by' => $this->triggeredByUserId,
            ]);
            $log->user_id = $this->triggeredByUserId;
            $log->ip_address = null;
            $log->save();
        } catch (Throwable $e) {
            Log::warning('Reembed job audit failed', ['error' => $e->getMessage()]);
        }
    }
}
