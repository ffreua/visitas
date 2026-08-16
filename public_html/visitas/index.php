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
|   /home/USUARIO/equipe/app/            (Laravel: vendor, bootstrap, storage, .env — privado)
|   /home/USUARIO/public_html/           (site principal existente, não mexer)
|   /home/USUARIO/public_html/visitas/   (este arquivo)
*/

define('LARAVEL_START', microtime(true));

$laravelBase = __DIR__.'/../../equipe/app';

if (file_exists($maintenance = $laravelBase.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravelBase.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravelBase.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
