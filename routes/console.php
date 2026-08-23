<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reportes programados (Tanda D3): una vez al día temprano se revisa qué toca y se encola. El comando decide por frecuencia
// (diaria/semanal/mensual); el despliegue sólo necesita el cron de `schedule:run` cada minuto.
Schedule::command('reports:run-scheduled')->dailyAt('06:00');
