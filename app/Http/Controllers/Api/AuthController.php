<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function login(Request $request): JsonResponse
    {
        $username = strtolower(trim((string) $request->input('username')));
        $password = (string) $request->input('password');

        if (empty($username) || empty($password)) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'Credenciales de acceso inválidas.',
                401
            );
        }

        // Buscar usuario en DB
        $user = User::where('username', $username)->first();

        // CA-01.2: El mensaje de error NO dice si falló el usuario o la contraseña
        if (!$user || !password_verify($password, $user->password_hash)) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'Credenciales de acceso inválidas.',
                401
            );
        }

        // Generar token JWT con 8 horas de vigencia
        $tokenData = $this->jwtService->generateToken($user->id, $user->role);

        return response()->json($tokenData);
    }
}
