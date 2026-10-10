<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookController extends Controller
{
    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $book->category->id ?? $book->category_id,
                'name' => $book->category->name ?? '',
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Book::with('category')->active();

        if (!empty($q)) {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'ILIKE', "%{$q}%")
                    ->orWhere('author', 'ILIKE', "%{$q}%");
            });
        }

        if (!empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        return response()->json([
            'items' => $books->map(fn($b) => $this->formatBook($b))->values(),
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $book = Book::with('category')->active()->find($id);

        if (!$book) {
            throw new BusinessException(
                'NOT_FOUND',
                'El libro solicitado no existe o ha sido dado de baja.',
                404
            );
        }

        return response()->json($this->formatBook($book));
    }

    public function store(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title'));
        $author = trim((string) $request->input('author'));
        $isbn = trim((string) $request->input('isbn'));
        $categoryId = $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies');

        if (empty($title) || empty($author) || empty($isbn) || empty($categoryId)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'Todos los campos son requeridos.',
                422
            );
        }

        // ISBN de 13 dígitos numéricos (CA-03.2)
        if (!preg_match('/^\d{13}$/', $isbn)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El ISBN debe contener exactamente 13 dígitos numéricos.',
                422,
                [['field' => 'isbn', 'issue' => 'debe tener 13 dígitos']]
            );
        }

        // Total de ejemplares >= 1 (CA-03.3)
        if ($totalCopies < 1) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El total de ejemplares debe ser mayor o igual a 1.',
                422,
                [['field' => 'total_copies', 'issue' => 'debe ser >= 1']]
            );
        }

        // Categoría válida
        if (!Category::where('id', $categoryId)->exists()) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'La categoría seleccionada no existe.',
                422,
                [['field' => 'category_id', 'issue' => 'categoría inexistente']]
            );
        }

        // ISBN único entre libros activos (CA-03.2)
        if (Book::active()->where('isbn', $isbn)->exists()) {
            throw new BusinessException(
                'ISBN_TAKEN',
                "El ISBN «{$isbn}» ya se encuentra registrado en un libro activo.",
                409,
                [['field' => 'isbn', 'issue' => 'ya existe']]
            );
        }

        $book = Book::create([
            'id' => (string) Str::uuid(),
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category_id' => $categoryId,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies, // CA-03.1: disponibles arrancan iguales al total
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        $book->load('category');

        return response()->json($this->formatBook($book), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $book = Book::with('category')->active()->find($id);

        if (!$book) {
            throw new BusinessException(
                'NOT_FOUND',
                'El libro no existe o ha sido dado de baja.',
                404
            );
        }

        $title = trim((string) $request->input('title', $book->title));
        $author = trim((string) $request->input('author', $book->author));
        $categoryId = $request->input('category_id', $book->category_id);
        $newTotal = (int) $request->input('total_copies', $book->total_copies);

        if ($newTotal < 1) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El total de ejemplares debe ser mayor o igual a 1.',
                422,
                [['field' => 'total_copies', 'issue' => 'debe ser >= 1']]
            );
        }

        // CA-03.3: Ajuste de disponibles según la diferencia del total
        $diff = $newTotal - $book->total_copies;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El cambio en total de ejemplares dejaría los disponibles en menos de 0.',
                422,
                [['field' => 'total_copies', 'issue' => 'disponibles quedarían < 0']]
            );
        }

        if (!Category::where('id', $categoryId)->exists()) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'La categoría seleccionada no existe.',
                422
            );
        }

        $book->title = $title;
        $book->author = $author;
        $book->category_id = $categoryId;
        $book->total_copies = $newTotal;
        $book->available_copies = $newAvailable;
        $book->save();

        $book->load('category');

        return response()->json($this->formatBook($book));
    }

    public function destroy(string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            throw new BusinessException(
                'NOT_FOUND',
                'El libro no existe o ya ha sido dado de baja.',
                404
            );
        }

        // CA-03.5: Un libro con préstamos abiertos no se da de baja (409)
        $hasOpenLoans = LoanItem::where('book_id', $book->id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            throw new BusinessException(
                'BOOK_HAS_OPEN_LOANS',
                "El libro «{$book->title}» tiene préstamos abiertos y no puede darse de baja.",
                409
            );
        }

        // Baja lógica (ADR-003)
        $book->deleted_at = now();
        $book->save();

        return response()->json([], 204);
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            throw new BusinessException(
                'NOT_FOUND',
                'El libro no existe.',
                404
            );
        }

        if (!$request->hasFile('file')) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'Se requiere un archivo en el campo file.',
                422
            );
        }

        $file = $request->file('file');

        // Validar tamaño máx. 2 MB (CA-03.4)
        if ($file->getSize() > 2 * 1024 * 1024) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'La portada no puede superar los 2 MB de tamaño.',
                422
            );
        }

        // Validar tipo de contenido por contenido (JPEG/PNG)
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png'])) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'Solo se admiten portadas en formato JPG o PNG.',
                422
            );
        }

        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $coverKey = (string) Str::uuid() . '.' . $ext;
        $coversDir = env('COVERS_DIR', storage_path('app/covers'));

        if (!File::exists($coversDir)) {
            File::makeDirectory($coversDir, 0755, true);
        }

        $file->move($coversDir, $coverKey);

        $book->cover_key = $coverKey;
        $book->save();

        return response()->json([], 204);
    }

    public function getCover(string $id)
    {
        $book = Book::find($id);

        if (!$book || !$book->cover_key) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Portada no encontrada.',
                'details' => [],
            ], 404);
        }

        $coversDir = env('COVERS_DIR', storage_path('app/covers'));
        $filePath = $coversDir . DIRECTORY_SEPARATOR . $book->cover_key;

        if (!File::exists($filePath)) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Archivo de portada no encontrado en el almacenamiento.',
                'details' => [],
            ], 404);
        }

        return new BinaryFileResponse($filePath);
    }

    public function deleteCover(string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            throw new BusinessException('NOT_FOUND', 'El libro no existe.', 404);
        }

        if ($book->cover_key) {
            $coversDir = env('COVERS_DIR', storage_path('app/covers'));
            $filePath = $coversDir . DIRECTORY_SEPARATOR . $book->cover_key;

            // Orden obligatorio (§7): primero anular cover_key, luego borrar archivo
            $oldKey = $book->cover_key;
            $book->cover_key = null;
            $book->save();

            if (File::exists($filePath)) {
                File::delete($filePath);
            }
        }

        return response()->json([], 204);
    }
}
