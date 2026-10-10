<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryContractTest extends TestCase
{
    private ?string $adminToken = null;
    private ?string $librarianToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Obtener token de admin
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'admin1234',
        ]);

        if ($loginRes->status() === 200) {
            $this->adminToken = $loginRes->json('access_token');
        }
    }

    private function getAdminHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * T-01: GET /health responde {"status": "ok"}
     */
    public function test_health_check_endpoint(): void
    {
        $response = $this->getJson('/health');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        $responseV1 = $this->getJson('/api/v1/health');
        $responseV1->assertStatus(200);
        $responseV1->assertJson(['status' => 'ok']);
    }

    /**
     * HU-01 / CA-01.1 & CA-01.2: Login con credenciales válidas e inválidas.
     */
    public function test_auth_login_contract(): void
    {
        // 200 Éxito
        $success = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'admin1234',
        ]);
        $success->assertStatus(200);
        $success->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'role']);
        $this->assertEquals(28800, $success->json('expires_in'));

        // 401 Credenciales inválidas (mensaje genérico sin delatar)
        $fail = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'clave_incorrecta',
        ]);
        $fail->assertStatus(401);
        $fail->assertJson([
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Credenciales de acceso inválidas.',
        ]);
        $fail->assertJsonStructure(['code', 'message', 'details']);
    }

    /**
     * CA-01.3: Rutas protegidas exigen token Bearer (401).
     */
    public function test_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/books');
        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'INVALID_CREDENTIALS',
        ]);
        $response->assertJsonStructure(['code', 'message', 'details']);
    }

    /**
     * CA-01.3: Rol insuficiente devuelve 403 FORBIDDEN_ROLE.
     */
    public function test_insufficient_role_returns_403(): void
    {
        // Crear un bibliotecario temporal
        $libUsername = 'lib_test_' . substr(Str::uuid(), 0, 8);
        $createRes = $this->postJson('/api/v1/users', [
            'username' => $libUsername,
            'password' => 'Temporal123!',
            'role' => 'librarian',
        ], $this->getAdminHeaders());

        $this->assertContains($createRes->status(), [201, 409]);

        // Login como bibliotecario
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'username' => $libUsername,
            'password' => 'Temporal123!',
        ]);

        if ($loginRes->status() === 200) {
            $libHeaders = [
                'Authorization' => 'Bearer ' . $loginRes->json('access_token'),
                'Accept' => 'application/json',
            ];

            // Intentar consultar reporte (solo admin)
            $response = $this->getJson('/api/v1/reports/most-borrowed?from=2026-10-01&to=2026-10-31', $libHeaders);
            $response->assertStatus(403);
            $response->assertJson(['code' => 'FORBIDDEN_ROLE']);
            $response->assertJsonStructure(['code', 'message', 'details']);
        }
    }

    /**
     * §3.3: Categorías sembradas de solo lectura.
     */
    public function test_categories_endpoint(): void
    {
        $response = $this->getJson('/api/v1/categories', $this->getAdminHeaders());
        $response->assertStatus(200);
        $this->assertCount(5, $response->json());
    }

    /**
     * §3.4: Búsqueda y listado paginado de libros.
     */
    public function test_books_pagination_and_detail(): void
    {
        $response = $this->getJson('/api/v1/books?page=1&size=10', $this->getAdminHeaders());
        $response->assertStatus(200);
        $response->assertJsonStructure(['items', 'page', 'size', 'total']);

        // Recurso inexistente devuelve 404
        $fakeUuid = (string) Str::uuid();
        $notFound = $this->getJson("/api/v1/books/{$fakeUuid}", $this->getAdminHeaders());
        $notFound->assertStatus(404);
        $notFound->assertJson(['code' => 'NOT_FOUND']);
        $notFound->assertJsonStructure(['code', 'message', 'details']);
    }

    /**
     * CA-03.2: ISBN duplicado entre libros activos responde 409.
     */
    public function test_duplicate_isbn_returns_409(): void
    {
        $existing = Book::active()->first();
        if ($existing) {
            $response = $this->postJson('/api/v1/books', [
                'title' => 'Libro Duplicado Test',
                'author' => 'Autor Test',
                'isbn' => $existing->isbn,
                'category_id' => $existing->category_id,
                'total_copies' => 2,
            ], $this->getAdminHeaders());

            $response->assertStatus(409);
            $response->assertJson(['code' => 'ISBN_TAKEN']);
            $response->assertJsonStructure(['code', 'message', 'details']);
        }
    }

    /**
     * CA-05.1: Documento de socio duplicado responde 409.
     */
    public function test_duplicate_member_document_returns_409(): void
    {
        $existing = Member::first();
        if ($existing) {
            $response = $this->postJson('/api/v1/members', [
                'full_name' => 'Otro Nombre Test',
                'document_number' => $existing->document_number,
            ], $this->getAdminHeaders());

            $response->assertStatus(409);
            $response->assertJson(['code' => 'DOCUMENT_TAKEN']);
            $response->assertJsonStructure(['code', 'message', 'details']);
        }
    }

    /**
     * CA-06.2: Libro duplicado dentro del mismo préstamo responde 422.
     */
    public function test_duplicate_book_in_loan_returns_422(): void
    {
        $member = Member::first();
        $book = Book::active()->where('available_copies', '>', 0)->first();

        if ($member && $book) {
            $response = $this->postJson('/api/v1/loans', [
                'member_id' => $member->id,
                'items' => [
                    ['book_id' => $book->id],
                    ['book_id' => $book->id],
                ],
            ], $this->getAdminHeaders());

            $response->assertStatus(422);
            $response->assertJson(['code' => 'DUPLICATE_BOOK_IN_LOAN']);
            $response->assertJsonStructure(['code', 'message', 'details']);
        }
    }

    /**
     * CA-10.1 & CA-10.2: Validación del reporte de más prestados.
     */
    public function test_most_borrowed_report_validations(): void
    {
        // Falta de parámetros obligatorios -> 422 VALIDATION_ERROR
        $missing = $this->getJson('/api/v1/reports/most-borrowed', $this->getAdminHeaders());
        $missing->assertStatus(422);
        $missing->assertJson(['code' => 'VALIDATION_ERROR']);
        $missing->assertJsonStructure(['code', 'message', 'details']);

        // Rango de fechas invertido (to < from) -> 422 INVALID_DATE_RANGE
        $inverted = $this->getJson('/api/v1/reports/most-borrowed?from=2026-10-31&to=2026-10-01', $this->getAdminHeaders());
        $inverted->assertStatus(422);
        $inverted->assertJson(['code' => 'INVALID_DATE_RANGE']);
        $inverted->assertJsonStructure(['code', 'message', 'details']);

        // Rango válido -> 200 OK con estructura { from, to, rows }
        $valid = $this->getJson('/api/v1/reports/most-borrowed?from=2026-10-01&to=2026-10-31', $this->getAdminHeaders());
        $valid->assertStatus(200);
        $valid->assertJsonStructure(['from', 'to', 'rows']);
    }

    /**
     * ADR-005: Gestión y baja segura de bibliotecarios.
     */
    public function test_librarians_lifecycle_contract(): void
    {
        // 1. Listar bibliotecarios (GET /users)
        $listRes = $this->getJson('/api/v1/users', $this->getAdminHeaders());
        $listRes->assertStatus(200);
        $listRes->assertJsonStructure(['items']);

        // 2. Intentar eliminar a un administrador responde 403
        $adminUser = User::where('role', 'admin')->first();
        if ($adminUser) {
            $delAdmin = $this->deleteJson("/api/v1/users/{$adminUser->id}", [], $this->getAdminHeaders());
            $delAdmin->assertStatus(403);
            $delAdmin->assertJson(['code' => 'FORBIDDEN_ROLE']);
            $delAdmin->assertJsonStructure(['code', 'message', 'details']);
        }

        // 3. Crear bibliotecario sin préstamos y darlo de baja exitosamente (204)
        $tempUser = 'temp_del_' . substr(Str::uuid(), 0, 8);
        $create = $this->postJson('/api/v1/users', [
            'username' => $tempUser,
            'password' => 'Pass12345!',
            'role' => 'librarian',
        ], $this->getAdminHeaders());

        if ($create->status() === 201) {
            $newId = $create->json('id');
            $deleteRes = $this->deleteJson("/api/v1/users/{$newId}", [], $this->getAdminHeaders());
            $deleteRes->assertStatus(204);
        }
    }
}
