<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Manager Library (/api/v1)
|--------------------------------------------------------------------------
*/

// Públicos
Route::get('/health', [HealthController::class, 'check']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

// Rutas protegidas por JWT
Route::middleware(['jwt.auth'])->group(function () {

    // Categorías
    Route::get('/categories', [CategoryController::class, 'index']);

    // Libros - Lectura
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{id}', [BookController::class, 'show']);

    // Socios (admin y librarian)
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']);
    Route::post('/members', [MemberController::class, 'store']);

    // Préstamos y Devoluciones (admin y librarian)
    Route::get('/loans', [LoanController::class, 'index']);
    Route::get('/loans/overdue', [LoanController::class, 'overdue']);
    Route::get('/loans/{id}', [LoanController::class, 'show']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);

    // Rutas exclusivas para Administrador ('admin')
    Route::middleware(['role:admin'])->group(function () {
        // Usuarios / Bibliotecarios
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        // Gestión de Libros
        Route::post('/books', [BookController::class, 'store']);
        Route::put('/books/{id}', [BookController::class, 'update']);
        Route::delete('/books/{id}', [BookController::class, 'destroy']);
        Route::match(['PUT', 'POST'], '/books/{id}/cover', [BookController::class, 'uploadCover']);
        Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

        // Reportes estadísticos
        Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
    });
});
