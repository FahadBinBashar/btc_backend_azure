<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends BaseApiController
{
    public function index(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $users = User::query()
            ->with('roles:user_id,role')
            ->latest('id')
            ->get([
                'id',
                'name',
                'email',
                'is_admin',
                'is_super_admin',
                'is_protected',
                'created_at',
                'last_login_at',
            ])
            ->map(function (User $user) {
                $roles = $this->resolveRoles($user);

                return [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $roles,
                    'is_admin' => in_array('admin', $roles, true),
                    'is_super_admin' => in_array('super_admin', $roles, true),
                    'is_protected' => (bool) $user->is_protected || in_array('super_admin', $roles, true),
                    'isSuperAdmin' => in_array('super_admin', $roles, true),
                    'joined_at' => optional($user->created_at)->toDateString(),
                    'created_at' => optional($user->created_at)->toISOString(),
                    'last_login_at' => optional($user->last_login_at)->toISOString(),
                ];
            })
            ->values();

        return $this->ok([
            'users' => $users,
            'total' => $users->count(),
        ]);
    }

    public function store(Request $request)
    {
        return $this->createAdmin($request);
    }

    public function createAdmin(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'fullName' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
        ]);

        $email = strtolower(trim($payload['email']));
        $displayName = trim((string) ($payload['fullName'] ?? $payload['name'] ?? 'Admin User'));

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            if ($this->userHasRole($user, 'admin')) {
                return $this->fail('This user is already an admin', 400);
            }

            $this->assignRoleToUser($user, 'admin');
            $user->is_admin = true;
            $user->save();

            return $this->ok([
                'message' => 'Admin role assigned to existing user',
                'user' => [
                    'id' => (string) $user->id,
                    'email' => $user->email,
                    'roles' => $this->resolveRoles($user->fresh('roles')),
                ],
            ]);
        }

        if (empty($payload['password'])) {
            return $this->fail('Password is required for new users', 400);
        }

        $user = User::query()->create([
            'name' => $displayName,
            'email' => $email,
            'password' => Hash::make((string) $payload['password']),
            'is_admin' => true,
            'is_super_admin' => false,
            'is_protected' => false,
        ]);

        $this->assignRoleToUser($user, 'admin');

        return $this->ok([
            'message' => 'Admin user created successfully',
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $this->resolveRoles($user->fresh('roles')),
            ],
        ]);
    }

    public function makeAdmin(Request $request, User $user)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $this->assignRoleToUser($user, 'admin');
        $user->is_admin = true;
        $user->save();

        return $this->ok([
            'message' => 'User promoted to admin.',
            'user' => [
                'id' => (string) $user->id,
                'roles' => $this->resolveRoles($user->fresh('roles')),
            ],
        ]);
    }

    public function removeAdmin(Request $request, User $user)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        if ($this->isProtectedUser($user)) {
            return $this->fail('This user is protected and cannot be demoted.', 422);
        }

        UserRole::query()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->delete();

        $user->is_admin = false;
        $user->save();

        return $this->ok([
            'message' => 'Admin role removed.',
            'user' => [
                'id' => (string) $user->id,
                'roles' => $this->resolveRoles($user->fresh('roles')),
            ],
        ]);
    }

    public function assignRole(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in(['admin', 'super_admin'])],
        ]);

        $user = User::query()->findOrFail($payload['userId']);
        if ($this->isSuperAdminEmail($user->email)) {
            return $this->fail('Cannot modify super admin roles', 403);
        }

        $this->assignRoleToUser($user, $payload['role']);

        if ($payload['role'] === 'super_admin') {
            $user->is_super_admin = true;
            $user->is_admin = true;
            $user->is_protected = true;
        } elseif ($payload['role'] === 'admin') {
            $user->is_admin = true;
        }
        $user->save();

        return $this->ok(['message' => 'Role assigned successfully']);
    }

    public function removeRole(Request $request)
    {
        if ($authError = $this->requireAdmin($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in(['admin', 'super_admin'])],
        ]);

        $user = User::query()->findOrFail($payload['userId']);
        if ($this->isSuperAdminEmail($user->email) || $this->isProtectedUser($user)) {
            return $this->fail('Cannot modify super admin roles', 403);
        }

        UserRole::query()
            ->where('user_id', $user->id)
            ->where('role', $payload['role'])
            ->delete();

        $remainingRoles = UserRole::query()
            ->where('user_id', $user->id)
            ->pluck('role')
            ->all();

        $user->is_super_admin = in_array('super_admin', $remainingRoles, true);
        $user->is_admin = in_array('admin', $remainingRoles, true) || $user->is_super_admin;
        $user->is_protected = $user->is_super_admin ? true : $user->is_protected;
        $user->save();

        return $this->ok(['message' => 'Role removed successfully']);
    }

    private function resolveRoles(User $user): array
    {
        $roles = $user->relationLoaded('roles')
            ? $user->roles->pluck('role')->all()
            : UserRole::query()->where('user_id', $user->id)->pluck('role')->all();

        if (empty($roles)) {
            if ($user->is_super_admin) {
                $roles[] = 'super_admin';
            }
            if ($user->is_admin || $user->is_super_admin) {
                $roles[] = 'admin';
            }
        }

        return array_values(array_unique($roles));
    }

    private function assignRoleToUser(User $user, string $role): void
    {
        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function userHasRole(User $user, string $role): bool
    {
        return UserRole::query()
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->exists()
            || ($role === 'admin' && ((bool) $user->is_admin || (bool) $user->is_super_admin))
            || ($role === 'super_admin' && (bool) $user->is_super_admin);
    }

    private function isSuperAdminEmail(string $email): bool
    {
        $superAdminEmail = strtolower(trim((string) env('SUPER_ADMIN_EMAIL', 'shawn@guidepoint.co.bw')));
        return strtolower(trim($email)) === $superAdminEmail;
    }

    private function isProtectedUser(User $user): bool
    {
        return $this->isSuperAdminEmail($user->email)
            || (bool) $user->is_super_admin
            || (bool) $user->is_protected;
    }
}
