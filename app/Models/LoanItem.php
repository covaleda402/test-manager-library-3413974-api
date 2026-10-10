<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LoanItem extends Model
{
    use HasUuids;

    protected $table = 'loan_item';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'loan_id',
        'book_id',
        'book_title',
        'isbn',
        'category_name',
        'due_at',
        'returned_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }
}
