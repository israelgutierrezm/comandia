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
    | 'label': nombre del módulo EN ESPAÑOL, para la interfaz. El identificador es
    | inglés porque es código (CLAUDE.md, sección Idioma); la etiqueta existe para
    | que la UI no acabe pintando `Costing` o `Pos` en crudo. Vive aquí y no en el
    | frontend a propósito: la pantalla de configuración y el editor de roles se
    | autoconfiguran desde la API (ADR-006), así que una tabla de traducciones en
    | Vue haría que agregar una llave o un permiso exigiera tocar el frontend —
    | exactamente lo que ese diseño evita.
    |
    */

    'modules' => [
        // ---- Shared kernel -------------------------------------------------
        'Shared' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'General'],
        'Tenancy' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Negocio'],
        'Identity' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Personal y accesos'],
        'Organization' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Sucursales y terminales'],
        'Configuration' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Configuración general'],
        'Audit' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Auditoría'],
        'Notifications' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 8, 'label' => 'Notificaciones'],

        // ---- Dominio -------------------------------------------------------
        'Catalog' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Catálogo'],
        'Costing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Costos y precios'],
        'Inventory' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Inventarios'],
        'Purchasing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Compras'],
        'Finance' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 5, 'label' => 'Finanzas'],
        'Customers' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 7, 'label' => 'Clientes'],

        // ---- Operaciones ---------------------------------------------------
        'Pos' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Punto de venta'],
        'Printing' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Impresión'],
        'Floor' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 6, 'label' => 'Salón y mesas'],
        'Promotions' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 7, 'label' => 'Promociones'],

        // ---- Analítica -----------------------------------------------------
        'Reporting' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8, 'label' => 'Reportes'],
        'Dashboards' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8, 'label' => 'Tableros'],

        // ---- Activables por tenant ----------------------------------------
        'DigitalMenus' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9, 'label' => 'Menús digitales'],
        'Ecommerce' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9, 'label' => 'Tienda en línea'],
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
