<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanController extends Controller
{
    private function formatLoan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => $loan->loaned_at->toISOString(),
            'status' => $loan->calculated_status,
            'items' => $loan->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'book_id' => $item->book_id,
                    'book_title' => $item->book_title,
                    'isbn' => $item->isbn,
                    'category_name' => $item->category_name,
                    'due_at' => $item->due_at->toISOString(),
                    'returned_at' => $item->returned_at ? $item->returned_at->toISOString() : null,
                ];
            })->values(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $statusFilter = $request->query('status');
        $memberId = $request->query('member_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Loan::with(['items', 'member']);

        if (!empty($memberId)) {
            $query->where('member_id', $memberId);
        }

        if (!empty($from)) {
            $query->where('loaned_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if (!empty($to)) {
            $query->where('loaned_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $allLoans = $query->orderBy('loaned_at', 'desc')->get();

        // Filtrado en memoria por estado calculado (Art. VII) si se solicita
        if (!empty($statusFilter)) {
            $allLoans = $allLoans->filter(function ($l) use ($statusFilter) {
                return $l->calculated_status === $statusFilter;
            })->values();
        }

        $total = $allLoans->count();
        $pagedLoans = $allLoans->slice(($page - 1) * $size, $size)->values();

        return response()->json([
            'items' => $pagedLoans->map(fn($l) => $this->formatLoan($l))->values(),
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $loan = Loan::with('items')->find($id);

        if (!$loan) {
            throw new BusinessException(
                'NOT_FOUND',
                'El préstamo solicitado no existe.',
                404
            );
        }

        return response()->json($this->formatLoan($loan));
    }

    public function store(Request $request): JsonResponse
    {
        $memberId = $request->input('member_id');
        $rawItems = $request->input('items', []);

        if (empty($memberId) || !is_array($rawItems)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El socio y los libros son requeridos.',
                422
            );
        }

        // CA-06.1: Préstamo trae de 1 a 3 líneas
        $itemCount = count($rawItems);
        if ($itemCount < 1 || $itemCount > 3) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'Un préstamo debe contener entre 1 y 3 libros.',
                422,
                [['field' => 'items', 'issue' => 'debe contener entre 1 y 3 libros']]
            );
        }

        // CA-06.2: No se repite el mismo libro en un préstamo (422)
        $bookIds = [];
        foreach ($rawItems as $it) {
            $bId = $it['book_id'] ?? null;
            if (!$bId) {
                throw new BusinessException('VALIDATION_ERROR', 'Cada línea debe tener book_id.', 422);
            }
            if (in_array($bId, $bookIds)) {
                throw new BusinessException(
                    'DUPLICATE_BOOK_IN_LOAN',
                    'No se puede repetir el mismo libro dentro del mismo préstamo.',
                    422,
                    [['field' => 'items', 'issue' => 'libro duplicado']]
                );
            }
            $bookIds[] = $bId;
        }

        $member = Member::find($memberId);
        if (!$member) {
            throw new BusinessException('NOT_FOUND', 'El socio indicado no existe.', 404);
        }

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro (409)
        if ($member->hasOverdueLoans()) {
            throw new BusinessException(
                'MEMBER_HAS_OVERDUE',
                "El socio «{$member->full_name}» tiene préstamos vencidos y no puede solicitar nuevos libros.",
                409
            );
        }

        // CA-06.4: Un socio no puede tener más de 3 libros sin devolver en total (422)
        $activeLoansCount = $member->getActiveLoansCount();
        if ($activeLoansCount + $itemCount > 3) {
            throw new BusinessException(
                'MEMBER_LIMIT_EXCEEDED',
                "El socio ya posee {$activeLoansCount} libros en préstamo. El límite máximo total es de 3.",
                422,
                [['field' => 'member_id', 'issue' => "posee {$activeLoansCount} libros activos"]]
            );
        }

        // Obtener libros y verificar disponibilidad
        $books = Book::with('category')->active()->whereIn('id', $bookIds)->get()->keyBy('id');
        foreach ($bookIds as $bId) {
            $book = $books->get($bId);
            if (!$book) {
                throw new BusinessException('NOT_FOUND', "El libro con ID «{$bId}» no existe o está inactivo.", 404);
            }
            if ($book->available_copies < 1) {
                throw new BusinessException(
                    'BOOK_NOT_AVAILABLE',
                    "No quedan ejemplares disponibles de «{$book->title}».",
                    409,
                    [['field' => 'items', 'issue' => 'sin ejemplares']]
                );
            }
        }

        $currentUser = $request->attributes->get('auth_user') ?? auth()->user();
        $loanedAt = $request->input('loaned_at') ? Carbon::parse($request->input('loaned_at')) : now();

        // Operación atómica transaccional (RNF-2, ADR-002, CA-06.3)
        $loan = DB::transaction(function () use ($memberId, $currentUser, $loanedAt, $bookIds, $books) {
            // Descontar ejemplares
            foreach ($bookIds as $bId) {
                $affected = DB::table('library.book')
                    ->where('id', $bId)
                    ->where('available_copies', '>', 0)
                    ->decrement('available_copies', 1);

                if ($affected === 0) {
                    $book = $books->get($bId);
                    throw new BusinessException(
                        'BOOK_NOT_AVAILABLE',
                        "Conflicto de concurrencia: se agotaron los ejemplares de «{$book->title}».",
                        409
                    );
                }
            }

            // Crear encabezado de préstamo
            $loanRecord = Loan::create([
                'id' => (string) Str::uuid(),
                'member_id' => $memberId,
                'loaned_by_user_id' => $currentUser->id,
                'loaned_at' => $loanedAt,
            ]);

            // Crear líneas congelando título, isbn y categoría en ese instante (CA-06.6 / Art. VIII)
            foreach ($bookIds as $bId) {
                $book = $books->get($bId);
                LoanItem::create([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loanRecord->id,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category->name ?? 'General',
                    'due_at' => $loanedAt->copy()->addDays(14), // 14 días exactos
                    'returned_at' => null,
                ]);
            }

            return $loanRecord;
        });

        $loan->load('items');

        return response()->json($this->formatLoan($loan), 201);
    }

    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        $item = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->first();

        if (!$item) {
            throw new BusinessException('NOT_FOUND', 'Línea de préstamo no encontrada.', 404);
        }

        // CA-07.2: Devolver una línea ya devuelta: 409. Nunca suma dos veces.
        if ($item->returned_at !== null) {
            throw new BusinessException(
                'ITEM_ALREADY_RETURNED',
                "La línea del libro «{$item->book_title}» ya fue devuelta previamente.",
                409
            );
        }

        // Operación atómica de devolución
        DB::transaction(function () use ($item) {
            $item->returned_at = now();
            $item->save();

            // Incrementar ejemplares disponibles en el catálogo
            DB::table('library.book')
                ->where('id', $item->book_id)
                ->increment('available_copies', 1);
        });

        return response()->json([
            'id' => $item->id,
            'book_id' => $item->book_id,
            'book_title' => $item->book_title,
            'isbn' => $item->isbn,
            'category_name' => $item->category_name,
            'due_at' => $item->due_at->toISOString(),
            'returned_at' => $item->returned_at->toISOString(),
        ]);
    }

    public function overdue(): JsonResponse
    {
        $now = now();

        // CA-09.1 & CA-09.2: Líneas sin devolver con due_at < now, ordenadas por más días de retraso primero
        $overdueItems = LoanItem::with('loan.member')
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->orderBy('due_at', 'asc') // due_at más antiguo = más días de retraso
            ->get();

        $rows = $overdueItems->map(function ($item) use ($now) {
            $due = Carbon::parse($item->due_at);
            $daysLate = (int) $due->diffInDays($now);

            return [
                'loan_id' => $item->loan_id,
                'item_id' => $item->id,
                'book_title' => $item->book_title,
                'member_name' => $item->loan->member->full_name ?? 'Socio Desconocido',
                'due_at' => $due->toISOString(),
                'days_late' => max(1, $daysLate),
            ];
        })->values();

        return response()->json(['items' => $rows]);
    }
}
