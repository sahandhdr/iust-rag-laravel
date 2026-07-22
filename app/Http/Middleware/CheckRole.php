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
    public function handle(Request $request, Closure $next, $roles = null): Response
    {
        // بررسی اینکه آیا کاربر وارد سیستم شده است یا خیر
        if (!Auth::check()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        // دریافت کاربر وارد شده
        $user = Auth::user();

        // بررسی نقش کاربر
        if ($roles) {
            $roleArray = explode('-', $roles);
            $hasAnyRole = false;
            foreach ($roleArray as $role) {
                if ($user->hasRole(trim($role))) {
                    $hasAnyRole = true;
                    break;
                }
            }
            if (!$hasAnyRole) {
                return $this->errorResponse('Forbidden', 403);
            }
        }


        return $next($request);
    }
}
