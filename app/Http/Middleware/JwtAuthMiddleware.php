<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;

class JwtAuthMiddleware
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'Se requiere encabezado Authorization: Bearer <token>.',
                401
            );
        }

        $token = substr($header, 7);
        $payload = $this->jwtService->validateToken($token);

        $user = User::find($payload->sub);
        if (!$user) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'Usuario no encontrado o dado de baja.',
                401
            );
        }

        $request->attributes->set('auth_user', $user);
        auth()->setUser($user);

        return $next($request);
    }
}
