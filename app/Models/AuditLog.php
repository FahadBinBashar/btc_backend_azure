<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'service_request_id',
        'action',
        'ip',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
