<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreelancerProfile extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'title'];

    protected $casts = ['id' => 'integer'];
}
