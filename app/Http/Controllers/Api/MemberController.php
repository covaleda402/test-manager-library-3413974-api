<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    private function formatMember(Member $member): array
    {
        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $member->getActiveLoansCount(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Member::query();

        if (!empty($q)) {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'ILIKE', "%{$q}%")
                    ->orWhere('document_number', 'LIKE', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        return response()->json([
            'items' => $members->map(fn($m) => $this->formatMember($m))->values(),
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $member = Member::find($id);

        if (!$member) {
            throw new BusinessException(
                'NOT_FOUND',
                'El socio solicitado no existe.',
                404
            );
        }

        return response()->json($this->formatMember($member));
    }

    public function store(Request $request): JsonResponse
    {
        $fullName = trim((string) $request->input('full_name'));
        $documentNumber = trim((string) $request->input('document_number'));

        if (empty($fullName) || empty($documentNumber)) {
            throw new BusinessException(
                'VALIDATION_ERROR',
                'El nombre completo y el documento son obligatorios.',
                422
            );
        }

        // CA-05.1: Documento único. Repetido: 409
        if (Member::where('document_number', $documentNumber)->exists()) {
            throw new BusinessException(
                'DOCUMENT_TAKEN',
                "El documento «{$documentNumber}» ya pertenece a un socio registrado.",
                409,
                [['field' => 'document_number', 'issue' => 'duplicado']]
            );
        }

        $member = Member::create([
            'id' => (string) Str::uuid(),
            'full_name' => $fullName,
            'document_number' => $documentNumber,
        ]);

        return response()->json($this->formatMember($member), 201);
    }
}
