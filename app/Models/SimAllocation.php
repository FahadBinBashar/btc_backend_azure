<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimAllocation extends Model
{
    protected $fillable = [
        'service_request_id',
        'msisdn',
        'allocation_type',
        'sim_type',
        'esim_lpa_code',
        'esim_qr_path',
        'shop_id',
        'pickup_reference',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
