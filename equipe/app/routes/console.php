<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Agendamento (seção 93-94, 126 do PRD)
|--------------------------------------------------------------------------
|
| Só funciona se o cPanel tiver Cron Job configurado chamando
| `php artisan schedule:run` a cada minuto (ver DEPLOYMENT_HOSTGATOR.md).
| A aplicação continua funcional mesmo sem cron — backup vira uma tarefa
| manual do admin nesse caso.
*/
Schedule::command('neurologia:backup')->dailyAt('03:00');
Schedule::command('exports:cleanup')->hourly();
