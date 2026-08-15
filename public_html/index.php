<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Entry point de produção — Laravel vive fora de public_html
|--------------------------------------------------------------------------
|
| Estrutura esperada (seção 5-7 do PRD):
|   /home/USUARIO/equipe/app/   (Laravel: vendor, bootstrap, storage, .env)
|   /home/USUARIO/public_html/  (este arquivo)
|
| Se "visitas" for uma SUBPASTA dentro de um public_html compartilhado com
| outro conteúdo (em vez de public_html ser o document root dedicado do
| domínio/subdomínio), ajuste os `__DIR__.'/../...'` abaixo para
| `__DIR__.'/../../...'` (um nível a mais).
*/

define('LARAVEL_START', microtime(true));

$laravelBase = __DIR__.'/../equipe/app';

if (file_exists($maintenance = $laravelBase.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelBase.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravelBase.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
