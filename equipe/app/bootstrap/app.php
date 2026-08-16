<?php

use App\Exceptions\StaleAdmissionException;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // SPA sem tela de login server-side — sem isto, uma requisição não-AJAX
        // (ex.: alguém colando uma URL de /api/* direto no navegador, ou um
        // monitor sem header Accept: application/json) faz o Laravel tentar
        // redirecionar para route('login'), que não existe, virando 500 em
        // vez de um 401/redirect adequado. Clientes reais (axios sempre manda
        // Accept: application/json) continuam recebendo o 401 JSON normal.
        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (StaleAdmissionException $e, $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();

// public_html/visitas/ é o document root deste app em produção — public_html/
// já tem outro site no domínio principal, este app fica na subpasta.
// Sem isso, public_path() (usado pelo fallback SPA em routes/web.php)
// resolve para o public/ interno do Laravel, que não existe no deploy real
// (ver DEPLOYMENT_HOSTGATOR.md).
$app->usePublicPath(dirname(__DIR__, 3).'/public_html/visitas');

return $app;
