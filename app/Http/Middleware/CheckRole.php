<?php

namespace App\Http\Middleware;

use App\Traits\v1\ApiResponser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    use ApiResponser;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('احراز هویت انجام نشده است.', 401);
        }

        // support both "role:admin,developer" and "role:admin|developer" style via variadic
        $required = $this->normalizeList($roles);

        if ($required === []) {
            return $next($request);
        }

        if (method_exists($user, 'hasAnyRole')) {
            $allowed = $user->hasAnyRole($required);
        } else {
            $allowed = false;
            foreach ($required as $role) {
                if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (!$allowed) {
            return $this->errorResponse('شما دسترسی لازم (نقش) برای این عملیات را ندارید.', 403);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $roles
     * @return list<string>
     */
    private function normalizeList(array $roles): array
    {
        $normalized = [];

        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $normalized[] = $part;
                }
            }
        }

        return array_values(array_unique($normalized));
    }
}
