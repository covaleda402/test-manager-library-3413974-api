<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $rawRole = (string) $request->input('role');

        // CA-02.3: Pedir el rol admin por la API responde 422
        if ($rawRole === 'admin') {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'No está permitido crear usuarios con rol administrador mediante la API.',
                422,
                [['field' => 'role', 'issue' => 'no puede ser admin']]
            );
        }

        if ($rawRole !== 'librarian') {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El rol solo puede ser librarian.',
                422,
                [['field' => 'role', 'issue' => 'debe ser librarian']]
            );
        }

        $rawUsername = (string) $request->input('username');
        $username = strtolower(trim($rawUsername));
        $password = (string) $request->input('password');

        if (empty($username)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El nombre de usuario es obligatorio.',
                422,
                [['field' => 'username', 'issue' => 'requerido']]
            );
        }

        if (empty($password)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'La contraseña es obligatoria.',
                422,
                [['field' => 'password', 'issue' => 'requerido']]
            );
        }

        // CA-02.2: Nombre de usuario único
        if (User::where('username', $username)->exists()) {
            throw new BusinessException(
                'USERNAME_TAKEN',
                "El nombre de usuario «{$username}» ya está en uso.",
                409,
                [['field' => 'username', 'issue' => 'duplicado']]
            );
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'librarian',
        ]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }

    public function index(): JsonResponse
    {
        $users = User::where('role', 'librarian')
            ->orderBy('username', 'asc')
            ->get(['id', 'username', 'role']);

        return response()->json([
            'items' => $users,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            throw new BusinessException(
                'NOT_FOUND',
                'El usuario solicitado no existe.',
                404
            );
        }

        if ($user->role === 'admin') {
            throw new BusinessException(
                'FORBIDDEN_ROLE',
                'No está permitido eliminar a un administrador.',
                403
            );
        }

        // Verificar si tiene préstamos registrados en el histórico
        $hasLoans = DB::table('library.loan')->where('loaned_by_user_id', $user->id)->exists();
        if ($hasLoans) {
            throw new BusinessException(
                'FORBIDDEN_OPERATION',
                "El bibliotecario «{$user->username}» tiene préstamos registrados en el histórico y no puede ser eliminado.",
                409
            );
        }

        $user->delete();

        return response()->json([], 204);
    }
}
