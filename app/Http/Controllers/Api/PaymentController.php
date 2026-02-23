<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class PaymentController extends BaseApiController
{
    public function record(Request $request)
    {
        return $this->ok(['message' => 'Payment transaction log endpoint stub']);
    }
}
