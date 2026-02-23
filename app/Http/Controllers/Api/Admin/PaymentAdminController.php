<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

class PaymentAdminController extends BaseApiController
{
    public function index(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $payments = PaymentTransaction::query()
            ->latest('id')
            ->limit(200)
            ->get([
                'id',
                'msisdn',
                'payment_method',
                'payment_type',
                'amount',
                'currency',
                'status',
                'service_type',
                'plan_name',
                'created_at',
            ]);

        return $this->ok([
            'payments' => $payments,
            'total' => $payments->count(),
        ]);
    }
}
