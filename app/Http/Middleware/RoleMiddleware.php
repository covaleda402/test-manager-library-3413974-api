<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->attributes->get('auth_user') ?? auth()->user();

        if (!$user) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'Usuario no autenticado.',
                401
            );
        }

        if (!in_array($user->role, $roles)) {
            throw new BusinessException(
                'FORBIDDEN_ROLE',
                'No tiene permisos suficientes para realizar esta acción.',
                403
            );
        }

        return $next($request);
    }
}
