<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    public function login(Request $request)
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $payload['email'])->first();
        if (!$user || !Hash::check($payload['password'], $user->password)) {
            return $this->fail('Invalid credentials', 401);
        }

        $hasAdminRole = UserRole::query()
            ->where('user_id', $user->id)
            ->whereIn('role', ['admin', 'super_admin'])
            ->exists();

        if (!$hasAdminRole && !$user->is_admin && !$user->is_super_admin) {
            return $this->fail('Access denied. Admin role required.', 403);
        }

        $user->last_login_at = now();
        $user->save();

        $isSuperAdmin = UserRole::query()
            ->where('user_id', $user->id)
            ->where('role', 'super_admin')
            ->exists() || (bool) $user->is_super_admin;

        $isAdmin = $isSuperAdmin
            || UserRole::query()->where('user_id', $user->id)->where('role', 'admin')->exists()
            || (bool) $user->is_admin;

        return $this->ok([
            'token' => (string) env('ADMIN_API_TOKEN', 'dev-admin-token'),
            'user' => [
                'email' => $user->email,
                'full_name' => $user->name,
                'is_admin' => $isAdmin,
                'is_super_admin' => $isSuperAdmin,
                'is_protected' => (bool) $user->is_protected || $isSuperAdmin,
            ],
        ]);
    }
}
