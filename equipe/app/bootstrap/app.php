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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (StaleAdmissionException $e, $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });
    })->create();

// public_html/ é o document root de produção, sibling de equipe/ (não
// equipe/app/public) — sem isso, public_path() (usado pelo fallback SPA
// em routes/web.php) resolve para o public/ interno do Laravel, que não
// existe no deploy real (ver DEPLOYMENT_HOSTGATOR.md).
$app->usePublicPath(dirname(__DIR__, 3).'/public_html');

return $app;
