<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'service_request_id',
        'msisdn',
        'payment_method',
        'payment_type',
        'amount',
        'currency',
        'status',
        'voucher_code',
        'customer_care_user_id',
        'service_type',
        'plan_name',
        'metadata',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount' => 'decimal:2',
    ];
}
