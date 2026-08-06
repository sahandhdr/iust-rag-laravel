<?php

namespace App\Traits\v1;

use App\Models\Log\Log;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Illuminate\Support\Facades\Log as Logger;
/**
 * Centralized audit logging for organizational actions.
 *
 * Usage in controllers:
 *   $this->audit('document.upload', 'document', $doc->id, ['file' => $name]);
 *   $this->auditFromRequest($request, 'document.publish', 'document', $id);
 *
 * Never throws — audit failure must not break the main business flow.
 */

trait Auditable
{
    /**
     * ثبت یک رکورد در audit_logs
     * در صورت خطا فقط warning می‌نویسد و false برمی‌گرداند.
     */
    protected function audit(string $action, string $entityType, $entityId = null, array $details = []): bool
    {
        try {
            $log = new Log();
            $log->action = $action;
            $log->entity_type = $entityType;
            $log->entity_id = $entityId !== null ? (string)$entityId : null;
            $log->details = $details;
            $log->ip_address = request()?->ip();
            $log->user_id = Auth::id();

            return $log->save();
        } catch (Throwable $e) {
            Logger::warning('audit-failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
//    /**
//     * Write one audit log row.
//     *
//     * @param  string  $action       e.g. document.upload | document.publish | auth.login
//     * @param  string  $entityType   e.g. document | user | role
//     * @param  int|string|null  $entityId
//     * @param  array<string, mixed>|null  $details
//     * @param  int|null  $userId     defaults to Auth::id()
//     * @param  string|null  $ipAddress defaults to request IP
//     */
//    protected function audit(
//        string $action,
//        string $entityType,
//        int|string|null $entityId = null,
//        ?array $details = null,
//        ?int $userId = null,
//        ?string $ipAddress = null
//    ): bool {
//        try {
//            $log = new Log();
//            $log->action = mb_substr(trim($action), 0, 50);
//            $log->entity_type = mb_substr(trim($entityType), 0, 50);
//            $log->entity_id = $entityId !== null ? (string) $entityId : null;
//            $log->details = $details ?? [];
//            $log->ip_address = $ipAddress
//                ?? (request()?->ip() ?? null);
//            $log->user_id = $userId ?? Auth::id();
//
//            return (bool) $log->save();
//        } catch (Throwable $e) {
//            // Fail-safe: never break the primary request
//            LaravelLog::warning('Audit log write failed', [
//                'action' => $action,
//                'entity_type' => $entityType,
//                'entity_id' => $entityId,
//                'error' => $e->getMessage(),
//            ]);
//
//            return false;
//        }
//    }
//
//    /**
//     * Convenience: audit with IP taken from current Request.
//     */
//    protected function auditFromRequest(
//        Request $request,
//        string $action,
//        string $entityType,
//        int|string|null $entityId = null,
//        ?array $details = null
//    ): bool {
//        return $this->audit(
//            $action,
//            $entityType,
//            $entityId,
//            $details,
//            Auth::id(),
//            $request->ip()
//        );
//    }
}
