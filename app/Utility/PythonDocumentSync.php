<?php

namespace App\Utility;

use App\Models\Document\Document;
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

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('services.python.timeout', 120);
    }

    /**
     * Plan A: same doc_uuid → overwrite chunks in Qdrant.
     *
     * @return array{ok: bool, skipped?: bool, status?: int, body?: mixed, error?: string, data?: mixed}
     */
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
            $request = Http::timeout($this->timeout)
                ->attach('file', $binary, $filename);

            if ($bearerToken) {
                $request = $request->withToken($bearerToken);
            }

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
            Log::error('Python ingest exception', [
                'doc_uuid' => $document->doc_uuid,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, skipped?: bool, status?: int, body?: mixed, error?: string}
     */
    public function deleteFromQdrant(string $docUuid, ?string $bearerToken = null): array
    {
        if ($docUuid === '') {
            return ['ok' => false, 'error' => 'empty-doc-uuid'];
        }

        try {
            $request = Http::timeout($this->timeout);
            if ($bearerToken) {
                $request = $request->withToken($bearerToken);
            }

            $response = $request->delete($this->baseUrl . '/api/v1/files/' . rawurlencode($docUuid));

            // idempotent: already gone is OK for archive/destroy
            if ($response->status() === 404) {
                return ['ok' => true, 'skipped' => true, 'status' => 404, 'body' => $response->json()];
            }

            return $this->wrap($response);
        } catch (Throwable $e) {
            Log::error('Python delete exception', [
                'doc_uuid' => $docUuid,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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
}
