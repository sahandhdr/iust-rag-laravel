<?php

namespace App\Utility;

use App\Models\Document\Document;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bridge Laravel document lifecycle → Python Qdrant.
 * MySQL/disk remain Laravel's responsibility.
 */
class PythonDocumentSync
{
    private string $baseUrl;
    private int $timeout;
    private int $ingestTimeout;
    private int $connectTimeout;
    private string $internalApiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = max(1, (int) config('services.python.timeout', 120));
        $this->ingestTimeout = max(1, (int) config('services.python.ingest_timeout', config('services.python.timeout', 300)));
        $this->connectTimeout = max(1, (int) config('services.python.connect_timeout', 15));
        $this->internalApiKey = (string) config('services.python.internal_api_key', '');
    }

    public function ingest(Document $document, ?string $bearerToken = null, bool $overwrite = true): array
    {
        if (!$document->path || !Storage::disk('public')->exists($document->path)) {
            return ['ok' => false, 'error' => 'file-not-on-disk'];
        }

        $document->loadMissing(['roles', 'departments', 'permissions']);

        $roles = $document->roles->pluck('title_en')->filter()->values()->all();
        $departments = $document->departments
            ->map(fn ($d) => $d->title_en ?? $d->name_en ?? $d->name ?? null)
            ->filter()
            ->values()
            ->all();
        $permissions = $document->permissions->pluck('title_en')->filter()->values()->all();

        if (count($roles) === 0) {
            $roles = ['public'];
        }
        if (count($departments) === 0) {
            $departments = ['public'];
        }

        $department = $departments[0] ?? 'public';
        $binary = Storage::disk('public')->get($document->path);
        $filename = $document->file_name ?: basename($document->path);

        try {
            $request = Http::timeout($this->ingestTimeout)
                ->connectTimeout($this->connectTimeout)
                ->attach('file', $binary, $filename);

            $request = $this->applyAuthHeaders($request, $bearerToken);

            $response = $request->post($this->baseUrl . '/api/v1/files/ingest', [
                'department'  => $department,
                'doc_uuid'    => (string) $document->doc_uuid,
                'status'      => $document->status ?: 'published',
                'version'     => (int) ($document->version ?: 1),
                'overwrite'   => $overwrite ? '1' : '0',
                'roles'       => json_encode(array_values($roles), JSON_UNESCAPED_UNICODE),
                'departments' => json_encode(array_values($departments), JSON_UNESCAPED_UNICODE),
                'permissions' => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE),
            ]);

            return $this->wrap($response);
        } catch (Throwable $e) {
            return $this->exceptionResult('Python ingest exception', (string) $document->doc_uuid, $e, $this->ingestTimeout);
        }
    }

    public function deleteFromQdrant(string $docUuid, ?string $bearerToken = null): array
    {
        if ($docUuid === '') {
            return ['ok' => false, 'error' => 'empty-doc-uuid'];
        }

        try {
            $request = Http::timeout($this->ingestTimeout)
                ->connectTimeout($this->connectTimeout);

            $request = $this->applyAuthHeaders($request, $bearerToken);

            $response = $request->delete($this->baseUrl . '/api/v1/files/' . rawurlencode($docUuid));

            if ($response->status() === 404) {
                return [
                    'ok'      => true,
                    'skipped' => true,
                    'status'  => 404,
                    'body'    => $response->json(),
                ];
            }

            return $this->wrap($response);
        } catch (Throwable $e) {
            return $this->exceptionResult('Python delete exception', $docUuid, $e, $this->ingestTimeout);
        }
    }

    public function wipeCollection(?string $bearerToken = null): array
    {
        try {
            $request = Http::timeout($this->ingestTimeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->asJson();

            $request = $this->applyAuthHeaders($request, $bearerToken);

            $response = $request->post($this->baseUrl . '/api/v1/sync/collection/wipe', [
                'confirm' => true,
            ]);

            return $this->wrap($response);
        } catch (Throwable $e) {
            return $this->exceptionResult('Python wipe-collection exception', 'collection', $e, $this->ingestTimeout);
        }
    }

    /**
     * Orphan markdown cleanup under Python data_dir.
     *
     * @return array{ok: bool, status?: int, body?: mixed, data?: mixed, error?: string, timeout?: bool}
     */
    public function cleanupOrphanData(
        bool $dryRun = true,
        bool $confirm = false,
        bool $removeEmptyDirs = true,
        bool $cleanupTempIngest = true,
        ?string $bearerToken = null
    ): array {
        try {
            $request = Http::timeout($this->ingestTimeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->asJson();

            $request = $this->applyAuthHeaders($request, $bearerToken);

            $response = $request->post($this->baseUrl . '/api/v1/sync/data/cleanup', [
                'dry_run'             => $dryRun,
                'confirm'             => $confirm,
                'remove_empty_dirs'   => $removeEmptyDirs,
                'cleanup_temp_ingest' => $cleanupTempIngest,
            ]);

            return $this->wrap($response);
        } catch (Throwable $e) {
            return $this->exceptionResult('Python data-cleanup exception', 'data', $e, $this->ingestTimeout);
        }
    }

    public function reembedAllPublished(): array
    {
        $docs = Document::query()
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->get();

        $token = $this->internalApiKey !== '' ? null : request()->bearerToken();

        $results = [];
        $ok = 0;
        $fail = 0;
        $timeoutCount = 0;

        foreach ($docs as $document) {
            $sync = $this->ingest($document, $token, true);
            $isTimeout = !empty($sync['timeout']);

            $entry = [
                'doc_uuid' => $document->doc_uuid,
                'id'       => $document->id,
                'ok'       => (bool) ($sync['ok'] ?? false),
                'detail'   => $sync,
            ];
            $results[] = $entry;

            if ($entry['ok']) {
                $ok++;
            } else {
                $fail++;
                if ($isTimeout) {
                    $timeoutCount++;
                }
            }
        }

        return [
            'total'         => $docs->count(),
            'ok'            => $ok,
            'fail'          => $fail,
            'timeout_count' => $timeoutCount,
            'results'       => $results,
        ];
    }

    private function applyAuthHeaders($request, ?string $bearerToken = null)
    {
        if ($this->internalApiKey !== '') {
            return $request->withHeaders([
                'X-Internal-Key' => $this->internalApiKey,
            ]);
        }

        if ($bearerToken) {
            return $request->withToken($bearerToken);
        }

        return $request;
    }

    private function wrap(Response $response): array
    {
        $body = $response->json();

        if ($response->successful()) {
            return [
                'ok'     => true,
                'status' => $response->status(),
                'body'   => $body,
                'data'   => is_array($body) ? ($body['data'] ?? $body) : $body,
            ];
        }

        return [
            'ok'     => false,
            'status' => $response->status(),
            'body'   => $body ?? $response->body(),
            'error'  => is_array($body) ? ($body['message'] ?? 'python-failed') : 'python-failed',
        ];
    }

    private function exceptionResult(string $logMessage, string $docUuid, Throwable $e, int $timeoutSeconds): array
    {
        $isTimeout = $this->isTimeoutException($e);

        Log::error($logMessage, [
            'doc_uuid' => $docUuid,
            'error'    => $e->getMessage(),
            'timeout'  => $isTimeout,
            'class'    => get_class($e),
        ]);

        if ($isTimeout) {
            return [
                'ok'              => false,
                'error'           => 'python-timeout',
                'timeout'         => true,
                'timeout_seconds' => $timeoutSeconds,
            ];
        }

        return [
            'ok'    => false,
            'error' => $e->getMessage() !== '' ? $e->getMessage() : 'python-connection-error',
        ];
    }

    private function isTimeoutException(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out') || str_contains($msg, 'cURL error 28')) {
                return true;
            }
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'cURL error 28')
            || str_contains($msg, 'operation timed out');
    }
}
