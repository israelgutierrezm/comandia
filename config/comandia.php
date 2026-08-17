<?php

declare(strict_types=1);

use App\Support\Queue;

return [

    /*
    |--------------------------------------------------------------------------
    | Registro de módulos
    |--------------------------------------------------------------------------
    |
    | Mapa declarativo de los módulos del monolito modular (ARQUITECTURA_MAESTRA
    | §2). Este archivo es la única fuente de verdad sobre qué módulos existen,
    | a qué capa pertenecen y si son activables por tenant.
    |
    | 'layer':
    |   kernel      → shared kernel; no depende de ningún módulo de dominio
    |   domain      → módulos de dominio; pueden depender del kernel
    |   operations  → POS y superficies operativas
    |   analytics   → reportes, dashboards, impresión
    |
    | 'activatable': true significa que un tenant sin el módulo contratado no
    | ejecuta una sola línea de su código (verificación por middleware).
    |
    | 'iteration': iteración de la hoja de ruta (§14) en la que se construye.
    | Mientras un módulo no llegue a su iteración, su carpeta existe vacía con
    | su archivo .module.md describiendo el alcance.
    |
    */

    'modules' => [
        // ---- Shared kernel -------------------------------------------------
        'Shared' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Tenancy' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Identity' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Organization' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Configuration' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Audit' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1],
        'Notifications' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 8],

        // ---- Dominio -------------------------------------------------------
        'Catalog' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2],
        'Costing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2],
        'Inventory' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3],
        'Purchasing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3],
        'Finance' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 5],
        'Customers' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 7],

        // ---- Operaciones ---------------------------------------------------
        'Pos' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4],
        'Printing' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4],
        'Floor' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 6],
        'Promotions' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 7],

        // ---- Analítica -----------------------------------------------------
        'Reporting' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8],
        'Dashboards' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8],

        // ---- Activables por tenant ----------------------------------------
        'DigitalMenus' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9],
        'Ecommerce' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9],
    ],

    /*
    |--------------------------------------------------------------------------
    | Colas
    |--------------------------------------------------------------------------
    |
    | Referencia legible del catálogo cerrado de App\Support\Queue. El código
    | usa el enum; esta lista existe para comandos de operación y documentación.
    |
    */

    'queues' => Queue::workerOrder(),

    /*
    |--------------------------------------------------------------------------
    | Rutas de API
    |--------------------------------------------------------------------------
    |
    | Prefijo versionado desde el día uno (ARQUITECTURA_MAESTRA §8). El cambio a
    | v2 será aditivo: se registra un segundo prefijo, nunca se muta este.
    |
    */

    'api' => [
        'prefix' => 'api/v1',
        'name_prefix' => 'api.v1.',
    ],

];
