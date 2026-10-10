<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Member extends Model
{
    use HasUuids;

    protected $table = 'member';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'full_name',
        'document_number',
    ];

    public function loans()
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    /**
     * Conteo de libros activos sin devolver (CA-06.4)
     */
    public function getActiveLoansCount(): int
    {
        return LoanItem::whereHas('loan', function ($q) {
            $q->where('member_id', $this->id);
        })->whereNull('returned_at')->count();
    }

    /**
     * Verificar si tiene libros vencidos (CA-06.5)
     */
    public function hasOverdueLoans(): bool
    {
        return LoanItem::whereHas('loan', function ($q) {
            $q->where('member_id', $this->id);
        })
        ->whereNull('returned_at')
        ->where('due_at', '<', now())
        ->exists();
    }
}
