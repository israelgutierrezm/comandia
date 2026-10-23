<?php

use Illuminate\Support\Facades\Schedule;

// Reportes programados (Tanda D3): una vez al día temprano se revisa qué toca y se encola. El comando decide por frecuencia
// (diaria/semanal/mensual); el despliegue sólo necesita el cron de `schedule:run` cada minuto.
Schedule::command('reports:run-scheduled')->dailyAt('06:00');

// Sesiones de la app sin usarse en 60 días (diseño de acceso, fase 1): se cierran solas. De madrugada, fuera del servicio.
Schedule::command('comandia:app-sessions:prune')->dailyAt('04:30');

// Lotes con la caducidad vencida (D377): se marcan «Caducado» para que el almacén los vea y registre su merma. El comando
// usa el día más atrasado de las sucursales, así que la hora nunca adelanta una caducidad; las 3 de la mañana del centro
// del país ya es el día siguiente en todas las zonas de México.
Schedule::command('comandia:lots:mark-expired')->dailyAt('03:00')->timezone('America/Mexico_City');

// Pedidos en línea sin pago (D380): a los 45 minutos pasan a «Pago vencido» y devuelven su cupón. Cada cinco minutos: un
// pedido abandonado no debe retener un cupón de un solo uso más de lo necesario. `withoutOverlapping` porque una corrida
// lenta no debe encimarse con la siguiente (el cierre es idempotente, pero no hace falta trabajar dos veces).
Schedule::command('comandia:online-orders:expire')->everyFiveMinutes()->withoutOverlapping();
