<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('login', function ($request) {
            return [
                // Por usuário+IP: impede força bruta contra uma conta específica.
                Limit::perMinute(5)->by(strtolower((string) $request->input('username')).'|'.$request->ip()),
                // Só por IP: sem isso, um atacante testa a senha padrão
                // (senha@1234) contra centenas de usuários do mesmo IP sem
                // nunca bater no limite por-usuário (password spraying).
                Limit::perMinute(20)->by('login-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('exports', function ($request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Reautenticação por senha em ações irreversíveis (hard delete,
        // zerar dados clínicos) — limita quantas tentativas de senha um
        // admin autenticado pode fazer, mesmo dentro de uma sessão legítima
        // (ex.: sessão sequestrada usada como oráculo de senha).
        RateLimiter::for('reauth', function ($request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
