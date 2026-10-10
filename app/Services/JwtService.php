<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JwtService
{
    private string $secret;
    private int $ttl = 28800; // 8 horas en segundos (CA-01.1)

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', 'super_secret_jwt_key_library_3413974_adso_2026');
    }

    public function generateToken(string $userId, string $role): array
    {
        $now = time();
        $payload = [
            'iss' => 'manager-library-api',
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
            'role' => $role,
        ];
    }

    public function validateToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable $e) {
            throw new BusinessException(
                'INVALID_CREDENTIALS',
                'El token de autorización es inválido o ha expirado.',
                401
            );
        }
    }
}
