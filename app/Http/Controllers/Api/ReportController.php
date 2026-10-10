<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (empty($from) || empty($to)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'Los parámetros de fecha from y to son obligatorios.',
                422,
                [['field' => 'from/to', 'issue' => 'parámetros requeridos']]
            );
        }

        try {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();
        } catch (\Throwable) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El formato de fecha debe ser válido (YYYY-MM-DD).',
                422
            );
        }

        // CA-10.2: Fin anterior al inicio: 422 INVALID_DATE_RANGE
        if ($fromDate->gt($toDate)) {
            throw new BusinessException(
                'INVALID_DATE_RANGE',
                'La fecha final no puede ser anterior a la fecha inicial.',
                422,
                [['field' => 'to', 'issue' => 'debe ser posterior o igual a from']]
            );
        }

        // Consulta agregada por valores congelados (ADR-004 & Art. VIII)
        $results = DB::table('library.loan_item')
            ->join('library.loan', 'loan.id', '=', 'loan_item.loan_id')
            ->where('loan.loaned_at', '>=', $fromDate)
            ->where('loan.loaned_at', '<=', $toDate)
            ->select(
                'loan_item.book_id',
                'loan_item.book_title',
                'loan_item.category_name',
                DB::raw('COUNT(*) as times_borrowed')
            )
            ->groupBy('loan_item.book_id', 'loan_item.book_title', 'loan_item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('loan_item.book_title', 'asc')
            ->get();

        $rows = $results->map(function ($row) {
            return [
                'book_id' => $row->book_id,
                'book_title' => $row->book_title,
                'category_name' => $row->category_name,
                'times_borrowed' => (int) $row->times_borrowed,
            ];
        })->values();

        return response()->json([
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'rows' => $rows,
        ]);
    }
}
