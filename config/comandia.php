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
    | 'depends_on': módulos de DOMINIO de los que este módulo puede depender en
    | código. La regla 1 de §2 ya permite que cualquier módulo dependa del shared
    | kernel, así que el kernel no se lista aquí: sería ruido en cada entrada.
    |
    | Una lista vacía significa "de ningún módulo de dominio", que es lo normal.
    | El grafo lo IMPONE tests/Architecture/ModuleBoundariesTest.php: una referencia
    | a un módulo no declarado falla la suite (D92 / P1 de la Iteración 2).
    |
    | Antes de esto, el candado sólo vigilaba que el kernel no dependiera de módulos
    | de dominio; dos módulos de dominio podían acoplarse en AMBOS sentidos sin que
    | nada protestara, que es exactamente cómo un monolito modular deja de serlo.
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
        // `depends_on` vacío no es un descuido: el kernel no depende de NADIE (§2, regla 1)
        // y hay un candado propio que lo verifica desde la Fase 0.
        'Shared' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'General', 'depends_on' => []],
        'Tenancy' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Negocio', 'depends_on' => []],
        'Identity' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Personal y accesos', 'depends_on' => []],
        'Organization' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Sucursales y terminales', 'depends_on' => []],
        'Configuration' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Configuración general', 'depends_on' => []],
        'Audit' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 1, 'label' => 'Auditoría', 'depends_on' => []],
        'Notifications' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 8, 'label' => 'Notificaciones', 'depends_on' => []],

        // ---- Dominio -------------------------------------------------------
        'Catalog' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Catálogo', 'depends_on' => []],

        // Costeo LEE catálogo y nunca le escribe (P1 de la Iteración 2). La FK
        // `recipe_lines.component_article_id` → `articles` hace la dependencia de datos
        // inevitable; ésta es la de código, declarada y vigilada.
        'Costing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Costos y precios', 'depends_on' => ['Catalog']],

        'Inventory' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Inventarios', 'depends_on' => ['Catalog', 'Costing']],
        'Purchasing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Compras', 'depends_on' => []],
        'Finance' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 5, 'label' => 'Finanzas', 'depends_on' => []],
        'Customers' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 7, 'label' => 'Clientes', 'depends_on' => []],

        // ---- Operaciones ---------------------------------------------------
        'Pos' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Punto de venta', 'depends_on' => []],
        'Printing' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Impresión', 'depends_on' => []],
        'Floor' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 6, 'label' => 'Salón y mesas', 'depends_on' => []],
        'Promotions' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 7, 'label' => 'Promociones', 'depends_on' => []],

        // ---- Analítica -----------------------------------------------------
        'Reporting' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8, 'label' => 'Reportes', 'depends_on' => []],
        'Dashboards' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 8, 'label' => 'Tableros', 'depends_on' => []],

        // ---- Activables por tenant ----------------------------------------
        'DigitalMenus' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9, 'label' => 'Menús digitales', 'depends_on' => []],
        'Ecommerce' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 9, 'label' => 'Tienda en línea', 'depends_on' => []],
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
