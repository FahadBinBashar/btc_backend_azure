<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaMapWebhookEvent extends Model
{
    protected $table = 'metamap_webhook_events';

    protected $fillable = [
        'provider',
        'event_name',
        'flow_id',
        'verification_id',
        'identity_id',
        'resource',
        'record_id',
        'service_request_id',
        'signature',
        'signature_valid',
        'event_timestamp',
        'metadata',
        'payload',
        'raw_payload',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'event_timestamp' => 'datetime',
        'metadata' => 'array',
        'payload' => 'array',
    ];
}
