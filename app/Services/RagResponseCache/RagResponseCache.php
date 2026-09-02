<?php

namespace App\Services\RagResponseCache;

use App\Utility\RedisRepo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exact-match RAG response cache (NOT vector search).
 * Key = hash(normalized query + roles + departments + cache generation).
 * Invalidation = bump generation (publish / archive / destroy).
 */
class RagResponseCache
{
    private RedisRepo $redis;

    private string $namespace = 'rag';

    private int $ttlSeconds;

    public function __construct(?RedisRepo $redis = null, int $ttlSeconds = 86400)
    {
        $this->redis = $redis ?? new RedisRepo();
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : 86400;
    }

    public function get(string $query, array $userAcl): ?array
    {
        try {
            $key = $this->buildKey($query, $userAcl);
            $cached = $this->redis->getJson($this->namespace, $key);

            if (!is_array($cached) || !array_key_exists('answer', $cached)) {
                return null;
            }

            return $cached;
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.get failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function set(string $query, array $userAcl, array $payload): bool
    {
        try {
            $key = $this->buildKey($query, $userAcl);

            $data = [
                'answer'  => $payload['answer'] ?? '',
                'sources' => $payload['sources'] ?? null,
                'cached_at' => now()->toIso8601String(),
            ];

            return $this->redis->setJson($this->namespace, $key, $data, $this->ttlSeconds);
        } catch (Throwable $e) {
            Log::warning('RagResponseCache.set failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Invalidate all exact caches by bumping generation (no KEYS/SCAN needed).
     */
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

    public function buildKey(string $query, array $userAcl): string
    {
        $normalized = $this->normalizeQuery($query);
        $roles = $this->normalizeList($userAcl['roles'] ?? []);
        $depts = $this->normalizeList($userAcl['departments'] ?? []);
        $gen = $this->generation();

        $material = $normalized . '|r:' . implode(',', $roles) . '|d:' . implode(',', $depts) . '|g:' . $gen;

        return 'ans:' . hash('sha256', $material);
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
