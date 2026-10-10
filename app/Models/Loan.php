<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Loan extends Model
{
    use HasUuids;

    protected $table = 'loan';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'member_id',
        'loaned_by_user_id',
        'loaned_at',
    ];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function loanedByUser()
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(LoanItem::class, 'loan_id');
    }

    /**
     * Estado calculado en tiempo de ejecución (Art. VII / CA-08.3):
     * - closed: todas las líneas devueltas
     * - overdue: alguna línea sin devolver y vencida
     * - open: hay líneas sin devolver y ninguna vencida
     */
    public function getCalculatedStatusAttribute(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return 'closed';
        }

        $allReturned = true;
        $now = now();

        foreach ($items as $item) {
            if ($item->returned_at === null) {
                $allReturned = false;
                if ($item->due_at < $now) {
                    return 'overdue';
                }
            }
        }

        if ($allReturned) {
            return 'closed';
        }

        return 'open';
    }
}
