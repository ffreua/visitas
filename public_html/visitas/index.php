<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Entry point de produção — Laravel vive fora de public_html
|--------------------------------------------------------------------------
|
| Estrutura real de produção (public_html já tem outro site no domínio
| principal; este app fica na subpasta /visitas):
|   /home/USUARIO/public_html/           (site principal existente, não mexer)
|   /home/USUARIO/public_html/visitas/   (este arquivo)
|
| A pasta do Laravel (equipe/app) fica FORA de public_html. Dependendo de
| onde ela foi colocada no cPanel, pode estar na raiz da conta ou dentro de
| uma pasta "private" — as duas posições são válidas e ambas ficam fora do
| alcance do navegador, então aceitamos as duas em vez de exigir uma.
*/

define('LARAVEL_START', microtime(true));

$laravelBase = null;

foreach ([
    __DIR__.'/../../equipe/app',          // ~/equipe/app
    __DIR__.'/../../private/equipe/app',  // ~/private/equipe/app
] as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $laravelBase = $candidate;
        break;
    }
}

if ($laravelBase === null) {
    http_response_code(500);
    exit('Aplicação não encontrada: a pasta "equipe/app" precisa estar fora de public_html, '
        .'na raiz da conta ou dentro de "private". Veja DEPLOYMENT_HOSTGATOR.md.');
}

if (file_exists($maintenance = $laravelBase.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelBase.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravelBase.'/bootstrap/app.php';

// Este arquivo É o document root do app, então aqui sabemos o public path com
// certeza — o palpite do bootstrap/app.php (que serve pro dev local e pro CLI)
// erraria se equipe/ estivesse aninhada dentro de private/.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
