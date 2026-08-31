<?php


namespace App\Utility;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Generic Redis repository — reusable across projects.
 *
 * Conventions:
 *  - Keys are always prefixed: {prefix}:{namespace}:{key}
 *  - Values: string by default; use *Json helpers for arrays/objects
 *  - Fail-soft by default (returns null/false and logs); strict mode throws
 *
 * Env (Laravel):
 *  REDIS_CLIENT=phpredis|predis
 *  REDIS_HOST=127.0.0.1
 *  REDIS_PASSWORD=null
 *  REDIS_PORT=6379
 *  REDIS_DB=0
 *  REDIS_PREFIX=iust_rag_   (optional; also set in config/database.php)
 */
class RedisRepo
{
    private string $connection;
    private string $appPrefix;
    private bool $strict;

    public function __construct(
        string  $connection = 'default',
        ?string $appPrefix = null,
        bool    $strict = false
    )
    {
        $this->connection = $connection;
        $this->appPrefix = $appPrefix ?? (string)config('database.redis.options.prefix', 'iust_rag_');
        $this->strict = $strict;
    }

    /**
     * Build full key: prefix + namespace + key
     * Example: key('chat', 'session:12:state') => iust_rag_chat:session:12:state
     * (Redis connection prefix may still apply on top — keep namespaces short and clear.)
     */
    public function key(string $namespace, string $key): string
    {
        $namespace = trim($namespace, ':');
        $key = ltrim($key, ':');

        return $namespace . ':' . $key;
    }

    // ------------------------------------------------------------------ CRUD-like
    public function get(string $namespace, string $key): ?string
    {
        try {
            $value = Redis::connection($this->connection)->get($this->key($namespace, $key));
            return $value === null ? null : (string)$value;
        } catch (Throwable $e) {
            return $this->fail('get', $e, null);
        }
    }

    public function set(string $namespace, string $key, string $value, ?int $ttlSeconds = null): bool
    {
        try {
            $full = $this->key($namespace, $key);
            $redis = Redis::connection($this->connection);

            if ($ttlSeconds !== null && $ttlSeconds > 0) {
                return (bool)$redis->setex($full, $ttlSeconds, $value);
            }

            return (bool)$redis->set($full, $value);
        } catch (Throwable $e) {
            return $this->fail('set', $e, false);
        }
    }

    public function setex(string $namespace, string $key, int $ttlSeconds, string $value): bool
    {
        return $this->set($namespace, $key, $value, $ttlSeconds);
    }

    public function getJson(string $namespace, string $key, mixed $default = null): mixed
    {
        $raw = $this->get($namespace, $key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->fail('getJson', $e, $default);
        }
    }

    public function setJson(string $namespace, string $key, mixed $data, ?int $ttlSeconds = null): bool
    {
        try {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $this->set($namespace, $key, $encoded, $ttlSeconds);
        } catch (Throwable $e) {
            return $this->fail('setJson', $e, false);
        }
    }

    public function del(string $namespace, string ...$keys): int
    {
        if (count($keys) === 0) {
            return 0;
        }

        try {
            $fullKeys = array_map(fn($k) => $this->key($namespace, $k), $keys);
            return (int)Redis::connection($this->connection)->del(...$fullKeys);
        } catch (Throwable $e) {
            return $this->fail('del', $e, 0);
        }
    }

    public function exists(string $namespace, string $key): bool
    {
        try {
            return (bool)Redis::connection($this->connection)->exists($this->key($namespace, $key));
        } catch (Throwable $e) {
            return $this->fail('exists', $e, false);
        }
    }

    public function expire(string $namespace, string $key, int $ttlSeconds): bool
    {
        try {
            return (bool)Redis::connection($this->connection)->expire(
                $this->key($namespace, $key),
                $ttlSeconds
            );
        } catch (Throwable $e) {
            return $this->fail('expire', $e, false);
        }
    }

    public function ttl(string $namespace, string $key): int
    {
        try {
            return (int)Redis::connection($this->connection)->ttl($this->key($namespace, $key));
        } catch (Throwable $e) {
            return $this->fail('ttl', $e, -2);
        }
    }

    // ------------------------------------------------------------------ counters
    public function incr(string $namespace, string $key, int $by = 1): int|false
    {
        try {
            $redis = Redis::connection($this->connection);
            $full = $this->key($namespace, $key);
            return $by === 1 ? (int)$redis->incr($full) : (int)$redis->incrby($full, $by);
        } catch (Throwable $e) {
            return $this->fail('incr', $e, false);
        }
    }

    public function decr(string $namespace, string $key, int $by = 1): int|false
    {
        try {
            $redis = Redis::connection($this->connection);
            $full = $this->key($namespace, $key);
            return $by === 1 ? (int)$redis->decr($full) : (int)$redis->decrby($full, $by);
        } catch (Throwable $e) {
            return $this->fail('decr', $e, false);
        }
    }

    // ------------------------------------------------------------------ hash (state objects)
    public function hGet(string $namespace, string $key, string $field): ?string
    {
        try {
            $v = Redis::connection($this->connection)->hget($this->key($namespace, $key), $field);
            return $v === null || $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return $this->fail('hGet', $e, null);
        }
    }

    public function hSet(string $namespace, string $key, string $field, string $value): bool
    {
        try {
            Redis::connection($this->connection)->hset($this->key($namespace, $key), $field, $value);
            return true;
        } catch (Throwable $e) {
            return $this->fail('hSet', $e, false);
        }
    }

    public function hGetAll(string $namespace, string $key): array
    {
        try {
            $all = Redis::connection($this->connection)->hgetall($this->key($namespace, $key));
            return is_array($all) ? $all : [];
        } catch (Throwable $e) {
            return $this->fail('hGetAll', $e, []);
        }
    }

    public function hDel(string $namespace, string $key, string ...$fields): int
    {
        if (count($fields) === 0) {
            return 0;
        }

        try {
            return (int)Redis::connection($this->connection)->hdel(
                $this->key($namespace, $key),
                ...$fields
            );
        } catch (Throwable $e) {
            return $this->fail('hDel', $e, 0);
        }
    }

    // ------------------------------------------------------------------ simple lock (coordination)

    /**
     * SET key value NX EX ttl — returns true if lock acquired.
     */
    public function acquireLock(string $namespace, string $key, int $ttlSeconds = 30, ?string $token = null): bool
    {
        $token = $token ?? bin2hex(random_bytes(8));

        try {
            $result = Redis::connection($this->connection)->set(
                $this->key($namespace, $key),
                $token,
                'EX',
                $ttlSeconds,
                'NX'
            );

            return (bool)$result;
        } catch (Throwable $e) {
            return $this->fail('acquireLock', $e, false);
        }
    }

    public function releaseLock(string $namespace, string $key, string $token): bool
    {
        try {
            $full = $this->key($namespace, $key);
            $redis = Redis::connection($this->connection);
            $current = $redis->get($full);

            if ($current !== null && (string)$current === $token) {
                $redis->del($full);
                return true;
            }

            return false;
        } catch (Throwable $e) {
            return $this->fail('releaseLock', $e, false);
        }
    }

    // ------------------------------------------------------------------ health
    public function ping(): bool
    {
        try {
            $pong = Redis::connection($this->connection)->ping();

            if ($pong === true || $pong === 1 || $pong === '1') {
                return true;
            }

            if (is_string($pong)) {
                $s = strtoupper(trim($pong));
                return $s === 'PONG' || $s === '+PONG';
            }

            // predis: Predis\Response\Status (و مشابه)
            if (is_object($pong) && method_exists($pong, '__toString')) {
                $s = strtoupper(trim((string) $pong));
                return $s === 'PONG' || $s === '+PONG';
            }

            // بعضی کلاینت‌ها payload می‌دهند
            if (is_object($pong) && property_exists($pong, 'payload')) {
                $s = strtoupper(trim((string) $pong->payload));
                return $s === 'PONG' || $s === '+PONG';
            }

            return false;
        } catch (Throwable $e) {
            return $this->fail('ping', $e, false);
        }
    }

    // ------------------------------------------------------------------
    private function fail(string $op, Throwable $e, mixed $fallback): mixed
    {
        Log::warning('RedisRepo.' . $op . ' failed', [
            'connection' => $this->connection,
            'error' => $e->getMessage(),
        ]);

        if ($this->strict) {
            throw $e;
        }

        return $fallback;
    }
}
