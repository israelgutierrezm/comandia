<?php

use Illuminate\Support\Facades\Schedule;

// Reportes programados (Tanda D3): una vez al día temprano se revisa qué toca y se encola. El comando decide por frecuencia
// (diaria/semanal/mensual); el despliegue sólo necesita el cron de `schedule:run` cada minuto.
Schedule::command('reports:run-scheduled')->dailyAt('06:00');

// Sesiones de la app sin usarse en 60 días (diseño de acceso, fase 1): se cierran solas. De madrugada, fuera del servicio.
Schedule::command('comandia:app-sessions:prune')->dailyAt('04:30');
