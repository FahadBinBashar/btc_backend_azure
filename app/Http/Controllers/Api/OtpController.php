<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class OtpController extends BaseApiController
{
    public function send(Request $request)
    {
        return $this->ok(['message' => 'OTP send endpoint stub']);
    }

    public function verify(Request $request)
    {
        return $this->ok(['message' => 'OTP verify endpoint stub']);
    }
}
