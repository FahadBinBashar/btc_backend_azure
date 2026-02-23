<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    protected $fillable = [
        'msisdn',
        'first_name',
        'last_name',
        'id_type',
        'id_number',
        'status',
        'is_whitelisted',
    ];

    protected $casts = [
        'is_whitelisted' => 'boolean',
    ];
}
