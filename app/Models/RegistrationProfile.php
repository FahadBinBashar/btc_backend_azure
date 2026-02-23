<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationProfile extends Model
{
    protected $fillable = [
        'service_request_id',
        'plot_number',
        'ward',
        'village',
        'city',
        'postal_address',
        'next_of_kin_name',
        'next_of_kin_relation',
        'next_of_kin_phone',
        'email',
    ];
}
