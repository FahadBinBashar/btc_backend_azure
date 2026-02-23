<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycVerification extends Model
{
    protected $fillable = [
        'service_request_id',
        'provider',
        'session_id',
        'verification_id',
        'identity_id',
        'status',
        'document_type',
        'full_name',
        'first_name',
        'surname',
        'date_of_birth',
        'sex',
        'country',
        'document_number',
        'expiry_date',
        'failure_reason',
        'selfie_url',
        'document_photo_urls',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'document_photo_urls' => 'array',
        'date_of_birth' => 'date',
        'expiry_date' => 'date',
    ];
}
