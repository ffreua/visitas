<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O papel OBSERVER (gestor observador) é somente leitura, e essa garantia
 * mora aqui — num único ponto por onde toda rota clínica e administrativa
 * passa — e não espalhada por Policy de cada recurso. Uma Policy esquecida
 * em um endpoint futuro abriria escrita por omissão; este middleware fecha
 * por omissão: qualquer verbo que não seja de leitura é recusado.
 *
 * As Policies também negam a escrita (defesa em profundidade), mas quem
 * responde primeiro é este middleware.
 *
 * Não cobre /auth/logout e /auth/change-password: ambos ficam fora do grupo
 * onde ele é aplicado, porque o observador precisa poder sair da conta e
 * trocar a própria senha.
 */
class DenyObserverWrites
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isObserver() && ! in_array($request->method(), self::READ_METHODS, true)) {
            return response()->json([
                'message' => 'Seu perfil é de gestor observador: acesso somente leitura, sem alterações no sistema.',
            ], 403);
        }

        return $next($request);
    }
}
