<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaseApiController extends Controller
{
    protected function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true] + $data, $status);
    }

    protected function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    protected function requireAdmin(Request $request): ?JsonResponse
    {
        $token = (string) env('ADMIN_API_TOKEN', 'dev-admin-token');
        $header = $request->bearerToken();

        if (!$header || $header !== $token) {
            return $this->fail('Unauthorized', 401);
        }

        return null;
    }
}
