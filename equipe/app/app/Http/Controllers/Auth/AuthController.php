<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password'], 'active' => true])) {
            AuditLogger::log('LOGIN_FAILED', 'User', $credentials['username']);

            throw ValidationException::withMessages([
                'username' => 'Usuário ou senha inválidos.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        AuditLogger::logModel('LOGIN', $user);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout()
    {
        $user = Auth::user();

        if ($user) {
            AuditLogger::logModel('LOGOUT', $user);
        }

        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me()
    {
        $user = Auth::user();

        return response()->json(['user' => $user ? $this->userPayload($user) : null]);
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $user = Auth::user();

        if (! Hash::check($request->string('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Senha atual incorreta.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('new_password')),
            'must_change_password' => false,
        ])->save();

        AuditLogger::logModel('PASSWORD_CHANGE', $user);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'username' => $user->username,
            'role' => $user->role,
            'must_change_password' => $user->must_change_password,
        ];
    }
}
