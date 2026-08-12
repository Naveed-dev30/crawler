<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpworkJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id', 'title', 'description', 'url', 'job_type',
        'budget_amount', 'hourly_min', 'hourly_max', 'currency',
        'posted_at', 'skills', 'client_country', 'client_total_spent',
        'client_payment_verified',
    ];

    protected $casts = [
        'skills' => 'array',
        'posted_at' => 'datetime',
        'client_payment_verified' => 'boolean',
        'client_total_spent' => 'float',
        'budget_amount' => 'float',
        'hourly_min' => 'float',
        'hourly_max' => 'float',
    ];
}
