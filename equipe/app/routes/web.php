<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API (mesma origem, autenticação por sessão/cookie Laravel)
|--------------------------------------------------------------------------
|
| Ficam dentro do grupo "web" (não "api") propositalmente: precisamos de
| sessão + CSRF, e o frontend React é servido pela mesma origem — não há
| necessidade de Sanctum/tokens. O Axios do frontend envia o cookie
| XSRF-TOKEN automaticamente como header X-XSRF-TOKEN.
|
*/
Route::prefix('api')->name('api.')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change-password');

        Route::middleware('password.changed')->group(function () {
            require __DIR__.'/api/patients.php';
            require __DIR__.'/api/admissions.php';
            require __DIR__.'/api/catalogs.php';
            require __DIR__.'/api/admin.php';
        });
    });
});

/*
|--------------------------------------------------------------------------
| SPA fallback
|--------------------------------------------------------------------------
|
| Qualquer rota que não seja /api/* devolve o shell do React (build estático)
| para que o roteamento client-side (react-router) funcione em refresh direto.
| Em produção este arquivo é public_html/visitas/index.html — junto com o
| resto do build (assets/, icons/, manifest, sw.js), sem subpasta própria:
| o build inteiro (Vite base=/visitas/) e o index.php do Laravel convivem
| no mesmo diretório. Uma subpasta "build/" faria o basename do
| react-router (derivado de import.meta.env.BASE_URL) ficar errado.
|
*/
Route::get('/{any}', function () {
    $indexPath = public_path('index.html');

    if (file_exists($indexPath)) {
        return response()->file($indexPath);
    }

    return response('Frontend ainda não compilado. Rode `npm run build` no diretório frontend/.', 200);
})->where('any', '^(?!api).*$')->name('spa');
