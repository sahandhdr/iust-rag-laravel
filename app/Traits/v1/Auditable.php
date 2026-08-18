<?php

namespace App\Traits\v1;

use App\Models\Log\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as Logger;
use Throwable;

/**
 * Centralized audit logging.
 *
 * Usage:
 *   $this->audit('auth.login', 'user', $user->id, ['username' => $user->username], $user->id);
 *   $this->audit('rag.ask', 'chat_session', $sessionId, ['status' => 'ok', 'human_message_id' => 1]);
 *
 * Never throws — audit failure must not break business flow.
 */
trait Auditable
{
    /**
     * @param  string  $action       e.g. auth.login | rag.ask
     * @param  string  $entityType   e.g. user | chat_session | document
     * @param  int|string|null  $entityId
     * @param  array<string, mixed>  $details
     * @param  int|null  $userId     اگر null → Auth::id()
     */
    protected function audit(
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        array $details = [],
        ?int $userId = null
    ): bool {
        try {
            $log = new Log();
            $log->action = mb_substr(trim($action), 0, 50);
            $log->entity_type = mb_substr(trim($entityType), 0, 50);
            $log->entity_id = $entityId !== null ? (string) $entityId : null;
            $log->details = $details;
            $log->ip_address = request()?->ip();
            $log->user_id = $userId ?? Auth::id();

            return (bool) $log->save();
        } catch (Throwable $e) {
            Logger::warning('audit-failed', [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);

            return false;
        }
    }
}
