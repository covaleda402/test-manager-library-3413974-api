<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwt.auth' => JwtAuthMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
        
        // CORS config for frontend access
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // BusinessException manejada según contrato HTTP
        $exceptions->render(function (BusinessException $e) {
            return response()->json([
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], $e->getStatusCode());
        });

        // ValidationException unificada según contrato (422)
        $exceptions->render(function (ValidationException $e) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $details[] = ['field' => $field, 'issue' => $msg];
                }
            }
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Los datos suministrados contienen errores de validación.',
                'details' => $details,
            ], 422);
        });

        // 404 Not Found
        $exceptions->render(function (NotFoundHttpException $e) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El recurso solicitado no fue encontrado.',
                'details' => [],
            ], 404);
        });

        // 405 Method Not Allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e) {
            return response()->json([
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Método HTTP no permitido para esta ruta.',
                'details' => [],
            ], 405);
        });
    })->create();
