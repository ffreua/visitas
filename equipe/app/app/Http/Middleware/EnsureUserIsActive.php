<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sem isso, desativar um usuário (Admin\UserController::deactivate) não
 * derrubava sessões já autenticadas — o guard de sessão só relê o usuário
 * do banco, nunca revalida `active`. Um médico desligado continuaria com
 * acesso a dados clínicos até a sessão expirar por inatividade.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Sua conta foi desativada.'], 401);
        }

        return $next($request);
    }
}
