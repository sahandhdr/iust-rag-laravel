<?php

namespace App\Http\Middleware;

use App\Traits\v1\ApiResponser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    use ApiResponser;
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('احراز هویت انجام نشده است.', 401);
        }

        $required = $this->normalizeList($permissions);

        if ($required === []) {
            return $next($request);
        }

        // admin/developer bypass optional — keep explicit via permission "all" or role check
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin', 'developer'])) {
            return $next($request);
        }

        if (method_exists($user, 'hasAnyPermission')) {
            $allowed = $user->hasAnyPermission($required);
        } else {
            $allowed = false;
            foreach ($required as $permission) {
                if (method_exists($user, 'hasPermission') && $user->hasPermission($permission)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (!$allowed) {
            return $this->errorResponse('شما دسترسی لازم (مجوز) برای این عملیات را ندارید.', 403);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private function normalizeList(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            foreach (explode(',', $permission) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $normalized[] = $part;
                }
            }
        }

        return array_values(array_unique($normalized));
    }
}
