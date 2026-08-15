<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const DEFAULT_PASSWORD = 'senha@1234';

    public function index()
    {
        $this->authorize('viewAny', User::class);

        return response()->json(User::orderBy('full_name')->paginate(30));
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'crm' => ['nullable', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'role' => ['required', Rule::in(['ADMIN', 'PHYSICIAN'])],
        ]);

        $user = User::create([
            ...$data,
            'uuid' => (string) Str::uuid(),
            'password' => Hash::make(self::DEFAULT_PASSWORD),
            'must_change_password' => true,
            'active' => true,
        ]);

        AuditLogger::logModel('USER_CREATE', $user);

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'crm' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', Rule::in(['ADMIN', 'PHYSICIAN'])],
        ]);

        $user->update($data);

        return response()->json($user);
    }

    public function deactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        $user->update(['active' => false]);

        AuditLogger::logModel('USER_DISABLE', $user);

        return response()->json($user);
    }

    public function reactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        $user->update(['active' => true]);

        return response()->json($user);
    }

    public function resetPassword(User $user)
    {
        $this->authorize('resetPassword', $user);

        $user->update([
            'password' => Hash::make(self::DEFAULT_PASSWORD),
            'must_change_password' => true,
        ]);

        AuditLogger::logModel('PASSWORD_RESET', $user);

        return response()->json(['message' => 'Senha redefinida para o padrão. Troca obrigatória no próximo login.']);
    }
}
