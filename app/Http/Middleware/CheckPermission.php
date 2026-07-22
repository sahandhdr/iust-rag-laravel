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
    public function handle(Request $request, Closure $next, $permissions = null): Response
    {
        if (!Auth::check()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $user = Auth::user();

        if ($permissions) {
            $permissionArray = explode('-', $permissions);
            $hasAnyPermission = false;
            foreach ($permissionArray as $permission) {
                if ($user->hasPermission(trim($permission))) {
                    $hasAnyPermission = true;
                    break;
                }
            }
            if (!$hasAnyPermission) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        return $next($request);
    }
}
