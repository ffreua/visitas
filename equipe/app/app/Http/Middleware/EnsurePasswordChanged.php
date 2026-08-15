<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia qualquer rota autenticada, exceto troca de senha/logout/me,
 * enquanto o usuário ainda estiver com a senha padrão (must_change_password).
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('api.auth.change-password', 'api.auth.logout', 'api.auth.me')) {
            return response()->json([
                'message' => 'Você deve criar uma nova senha antes de continuar.',
                'must_change_password' => true,
            ], 423);
        }

        return $next($request);
    }
}
