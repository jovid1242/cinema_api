<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movie extends Model
{
    protected $fillable = [
        'title',
        'description',
        'poster_url',
        'duration_minutes',
        'director',
        'genre',
        'release_year',
        'rating',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'rating' => 'decimal:1',
        'duration_minutes' => 'integer',
        'release_year' => 'integer'
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
} 