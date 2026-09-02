<?php

namespace App\Services\RagResponseCache;

use App\Utility\RedisRepo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RagResponseCache
{
    private RedisRepo $redis;
    private string $prefix = 'rag:cache:';

    public function __construct(RedisRepo $redis)
    {
        $this->redis = $redis;
    }

    /**
     * نرمال‌سازی query + ACL کاربر برای کلید cache
     */
    private function normalizeKey(string $query, array $userAcl): string
    {
        $roles = collect($userAcl['roles'])->pluck('name_en')->unique()->sort()->implode(',');
        $depts = collect($userAcl['departments'])->pluck('title_en')->unique()->sort()->implode(',');

        $hash = Str::uuid(Str::slug($query) . $roles . $depts);

        return $hash;
    }

    /**
     * خواندن از cache
     */
    public function get(string $query, array $userAcl): ?array
    {
        $key = $this->prefix . $this->normalizeKey($query, $userAcl);

        $cached = $this->redis->getJson('rag', $key);

        if ($cached && isset($cached['answer'])) {
            Log::info('RagResponseCache HIT', ['key' => $key]);
            return $cached;
        }

        Log::info('RagResponseCache MISS', ['key' => $key]);
        return null;
    }

    /**
     * نوشتن به cache (بعد از موفقیت Python)
     */
    public function set(string $query, array $userAcl, array $response): bool
    {
        $key = $this->prefix . $this->normalizeKey($query, $userAcl);

        $data = [
            'answer'     => $response['answer'],
            'sources'    => $response['sources'],
            'expires_at' => now()->addHours(24),
        ];

        return $this->redis->setJson('rag', $key, $data, 86400);
    }

    /**
     * پاک‌سازی cache وقتی سند تغییر کرد (publish/archive/destroy)
     */
    public function invalidateOnDocumentChange(string $docUuid): bool
    {
        // این روش را بعداً با pattern observer یا event پیاده می‌کنیم
        // برای Phase 1 کافی است وقتی publish می‌کنیم cache را خالی کنیم
        return true; // placeholder
    }
}
