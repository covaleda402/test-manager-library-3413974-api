<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Book extends Model
{
    use HasUuids;

    protected $table = 'book';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'title',
        'author',
        'isbn',
        'category_id',
        'total_copies',
        'available_copies',
        'cover_key',
        'deleted_at',
    ];

    protected $casts = [
        'total_copies' => 'integer',
        'available_copies' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function loanItems()
    {
        return $this->hasMany(LoanItem::class, 'book_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }
}
