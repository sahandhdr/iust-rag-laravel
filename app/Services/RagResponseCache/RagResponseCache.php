<?php

namespace App\Services\RagResponseCache;

use App\Utility\RedisRepo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Exact + Semantic RAG response cache.
 *
 * Exact:  hash(normalized query + ACL + generation)
 * Semantic: embed query via Python /api/v1/embed/query, cosine vs candidates in same ACL bucket
 *
 * Invalidation: incr cache_generation (publish/archive/destroy/wipe/cacheClear)
 */
class RagResponseCache
{
    private RedisRepo $redis;

    private string $namespace = 'rag';

    private int $ttlSeconds;

    private bool $semanticEnabled;

    private float $semanticThreshold;

    private int $semanticMaxCandidates;

    private int $embedTimeout;

    public function __construct(?RedisRepo $redis = null, ?int $ttlSeconds = null)
    {
        $this->redis = $redis ?? new RedisRepo();
        $this->ttlSeconds = $ttlSeconds ?? max(60, (int) config('services.rag.cache_ttl', 86400));
        $this->semanticEnabled = (bool) config('services.rag.semantic_cache_enabled', true);
        $this->semanticThreshold = (float) config('services.rag.semantic_threshold', 0.92);
        $this->semanticMaxCandidates = max(5, (int) config('services.rag.semantic_max_candidates', 50));
        $this->embedTimeout = max(5, (int) config('services.rag.embed_timeout', 30));
    }

    /**
     * Lookup: exact first, then semantic (if enabled).
     * Returns payload with optional _cache_kind: exact|semantic
     */
    public function get(string $query, array $userAcl): ?array
    {
        try {
            $exact = $this->getExact($query, $userAcl);
            if ($exact !== null) {
                $exact['_cache_kind'] = 'exact';
                return $exact;
            }

            if (!$this->semanticEnabled) {
                return null;
            }

            $semantic = $this->getSemantic($query, $userAcl);
            if ($semantic !== null) {
                $semantic['_cache_kind'] = 'semantic';
                return $semantic;
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.get failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function set(string $query, array $userAcl, array $payload): bool
    {
        try {
            $ok = $this->setExact($query, $userAcl, $payload);
            if ($this->semanticEnabled) {
                $this->setSemantic($query, $userAcl, $payload);
            }
            return $ok;
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.set failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function invalidateAll(): bool
    {
        try {
            $n = $this->redis->incr($this->namespace, 'cache_generation');
            if ($n === false) {
                return $this->redis->set($this->namespace, 'cache_generation', '1');
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.invalidateAll failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ------------------------------------------------------------------ exact

    protected function getExact(string $query, array $userAcl): ?array
    {
        $key = $this->buildExactKey($query, $userAcl);
        $cached = $this->redis->getJson($this->namespace, $key);

        if (!is_array($cached) || !array_key_exists('answer', $cached)) {
            return null;
        }

        return $cached;
    }

    protected function setExact(string $query, array $userAcl, array $payload): bool
    {
        $key = $this->buildExactKey($query, $userAcl);
        $data = [
            'answer'    => $payload['answer'] ?? '',
            'sources'   => $payload['sources'] ?? null,
            'cached_at' => now()->toIso8601String(),
        ];

        return $this->redis->setJson($this->namespace, $key, $data, $this->ttlSeconds);
    }

    // ------------------------------------------------------------------ semantic

    protected function getSemantic(string $query, array $userAcl): ?array
    {
        $vector = $this->embedQuery($query);
        if ($vector === null || count($vector) === 0) {
            return null;
        }

        $indexKey = $this->semanticIndexKey($userAcl);
        $entryIds = $this->listIndex($indexKey);
        if (count($entryIds) === 0) {
            return null;
        }

        $best = null;
        $bestScore = -1.0;

        foreach ($entryIds as $entryId) {
            $entry = $this->redis->getJson($this->namespace, 'sem:entry:'.$entryId);
            if (!is_array($entry) || empty($entry['vector']) || !isset($entry['answer'])) {
                continue;
            }
            $score = $this->cosineSimilarity($vector, $entry['vector']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        if ($best === null || $bestScore < $this->semanticThreshold) {
            return null;
        }

        return [
            'answer'         => $best['answer'],
            'sources'        => $best['sources'] ?? null,
            'cached_at'      => $best['cached_at'] ?? null,
            'similarity'     => $bestScore,
            'matched_query'  => $best['query_norm'] ?? null,
        ];
    }

    protected function setSemantic(string $query, array $userAcl, array $payload): void
    {
        $vector = $this->embedQuery($query);
        if ($vector === null || count($vector) === 0) {
            return;
        }

        $entryId = $this->buildExactKey($query, $userAcl);
        // strip ans: prefix for shorter entry id if present
        $entryId = str_starts_with($entryId, 'ans:') ? substr($entryId, 4) : $entryId;

        $entry = [
            'vector'     => $vector,
            'answer'     => $payload['answer'] ?? '',
            'sources'    => $payload['sources'] ?? null,
            'query_norm' => $this->normalizeQuery($query),
            'cached_at'  => now()->toIso8601String(),
        ];

        $this->redis->setJson($this->namespace, 'sem:entry:'.$entryId, $entry, $this->ttlSeconds);

        $indexKey = $this->semanticIndexKey($userAcl);
        $this->pushIndex($indexKey, $entryId);
    }

    /**
     * Call Python embed endpoint. Fail-soft → null.
     */
    protected function embedQuery(string $query): ?array
    {
        try {
            $base = rtrim((string) config('services.python.base_url', 'http://127.0.0.1:8001'), '/');
            $internalKey = (string) config('services.python.internal_api_key', '');

            $req = Http::timeout($this->embedTimeout)
                ->connectTimeout(min(15, $this->embedTimeout))
                ->acceptJson()
                ->asJson();

            if ($internalKey !== '') {
                $req = $req->withHeaders(['X-Internal-Key' => $internalKey]);
            } else {
                $token = request()?->bearerToken();
                if ($token) {
                    $req = $req->withToken($token);
                }
            }

            $response = $req->post($base.'/api/v1/embed/query', [
                'text' => $query,
            ]);

            if (!$response->successful()) {
                Log::warning('RagResponseCache.embedQuery http failed', [
                    'status' => $response->status(),
                    'body'   => $response->json() ?? $response->body(),
                ]);
                return null;
            }

            $body = $response->json();
            $data = is_array($body) ? ($body['data'] ?? $body) : null;
            $vector = is_array($data) ? ($data['vector'] ?? null) : null;

            if (!is_array($vector) || count($vector) === 0) {
                return null;
            }

            return array_map('floatval', $vector);
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.embedQuery exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    protected function semanticIndexKey(array $userAcl): string
    {
        $roles = $this->normalizeList($userAcl['roles'] ?? []);
        $depts = $this->normalizeList($userAcl['departments'] ?? []);
        $acl = 'r:'.implode(',', $roles).'|d:'.implode(',', $depts);
        $bucket = hash('sha256', $acl);

        return 'sem:index:'.$bucket.':'.$this->generation();
    }

    protected function listIndex(string $indexKey): array
    {
        try {
            $full = $this->redis->key($this->namespace, $indexKey);
            $ids = Redis::connection()->lrange($full, 0, $this->semanticMaxCandidates - 1);
            return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.listIndex failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function pushIndex(string $indexKey, string $entryId): void
    {
        try {
            $full = $this->redis->key($this->namespace, $indexKey);
            $redis = Redis::connection();
            $redis->lpush($full, $entryId);
            $redis->ltrim($full, 0, $this->semanticMaxCandidates - 1);
            $redis->expire($full, $this->ttlSeconds);
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.pushIndex failed', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ keys / normalize

    public function buildKey(string $query, array $userAcl): string
    {
        return $this->buildExactKey($query, $userAcl);
    }

    protected function buildExactKey(string $query, array $userAcl): string
    {
        $normalized = $this->normalizeQuery($query);
        $roles = $this->normalizeList($userAcl['roles'] ?? []);
        $depts = $this->normalizeList($userAcl['departments'] ?? []);
        $gen = $this->generation();

        $material = $normalized.'|r:'.implode(',', $roles).'|d:'.implode(',', $depts).'|g:'.$gen;

        return 'ans:'.hash('sha256', $material);
    }

    private function generation(): string
    {
        $g = $this->redis->get($this->namespace, 'cache_generation');
        if ($g === null || $g === '') {
            $this->redis->set($this->namespace, 'cache_generation', '1');
            return '1';
        }
        return (string) $g;
    }

    private function normalizeQuery(string $query): string
    {
        $q = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        return mb_strtolower($q, 'UTF-8');
    }

    private function normalizeList($list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            if (is_string($item) || is_numeric($item)) {
                $out[] = (string) $item;
            } elseif (is_array($item)) {
                $out[] = (string) ($item['title_en'] ?? $item['name_en'] ?? $item['name'] ?? '');
            }
        }

        $out = array_values(array_unique(array_filter($out, fn ($v) => $v !== '')));
        sort($out);

        return $out;
    }
}
