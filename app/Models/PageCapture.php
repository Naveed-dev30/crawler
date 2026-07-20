<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageCapture extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'url',
        'scraped_at',
        'payload',
        'content_hash',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
    ];

    public function scopeLatestForSource($query, string $source)
    {
        return $query->where('source', $source)->orderByDesc('scraped_at');
    }
}
