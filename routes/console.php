<?php

use Illuminate\Support\Facades\Schedule;

// Reportes programados (Tanda D3): una vez al día temprano se revisa qué toca y se encola. El comando decide por frecuencia
// (diaria/semanal/mensual); el despliegue sólo necesita el cron de `schedule:run` cada minuto.
Schedule::command('reports:run-scheduled')->dailyAt('06:00');
