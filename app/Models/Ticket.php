<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'row_number',
        'seat_number',
        'price',
        'status'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'row_number' => 'integer',
        'seat_number' => 'integer',
        'status' => 'string'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
} 