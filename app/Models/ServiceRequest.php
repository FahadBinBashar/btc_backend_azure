<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    protected $fillable = [
        'request_type',
        'user_id',
        'msisdn',
        'status',
        'current_step',
        'otp_skipped',
        'metadata',
    ];

    protected $casts = [
        'otp_skipped' => 'boolean',
        'metadata' => 'array',
    ];
}
