<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    protected $fillable = [
        'movie_id',
        'hall_id',
        'start_time',
        'price',
        'is_active'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'price' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
} 