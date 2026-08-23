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
        'Notifications' => ['layer' => 'kernel', 'activatable' => false, 'iteration' => 7, 'label' => 'Notificaciones', 'depends_on' => []],

        // ---- Dominio -------------------------------------------------------
        'Catalog' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Catálogo', 'depends_on' => []],

        // Costeo LEE catálogo y nunca le escribe (P1 de la Iteración 2). La FK
        // `recipe_lines.component_article_id` → `articles` hace la dependencia de datos
        // inevitable; ésta es la de código, declarada y vigilada.
        // `Purchasing` por lo mismo que en `Inventory`: el oyente que estrena `CostOrigin::Purchase` al confirmar
        // una recepción (Iteración 3, paso 9). Conoce el evento; compras no conoce el historial de costos.
        'Costing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 2, 'label' => 'Costos y precios', 'depends_on' => ['Catalog', 'Purchasing']],

        // `Purchasing` aparece por los OYENTES: confirmar una recepción emite un evento del que este módulo
        // registra los movimientos (§3.2). La flecha parece invertida —lo natural sería que compras dependiera de
        // inventarios— y no lo está: lo que este módulo conoce es el EVENTO, no la tabla. Compras no le escribe nada.
        //
        // Y el grafo se queda sin ciclos a propósito: `PurchaseReceiptLine` NO declara relaciones de Eloquent hacia
        // `article_lots` ni `stock_movements`, sólo sus FK. Ver el comentario en ese modelo.
        'Inventory' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Inventarios', 'depends_on' => ['Catalog', 'Costing', 'Purchasing']],
        'Purchasing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 3, 'label' => 'Compras', 'depends_on' => ['Catalog']],
        'Finance' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 4, 'label' => 'Finanzas', 'depends_on' => []],
        // `Customers` depende de `Finance` porque un abono entra por un método de pago —efectivo, transferencia— y los
        // métodos son de finanzas. Las dos son de dominio y `Finance` no depende de `Customers`, así que la flecha va en
        // un solo sentido y no hay ciclo.
        'Customers' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 4, 'label' => 'Clientes', 'depends_on' => ['Finance']],

        // Capa de publicación compartida (Iteración 8): descripción larga, galería y orden de los artículos, que consumen
        // TANTO Menús como Tienda. NO es activable —está siempre disponible— para que un negocio con sólo uno de los dos
        // módulos activables tenga igual su capa de publicación, sin acoplar Menús y Tienda entre sí. Fiel a ADR-007: la
        // publicación es una capa que AGREGA al artículo, fuera del Core. Depende de `Catalog` porque enriquece sus
        // artículos (FK a `articles`); `Catalog` no la conoce.
        'Publishing' => ['layer' => 'domain', 'activatable' => false, 'iteration' => 8, 'label' => 'Publicación', 'depends_on' => ['Catalog']],

        // ---- Operaciones ---------------------------------------------------
        // El POS depende de `Finance` para los MÉTODOS DE PAGO: los necesita para cobrar y para declarar el efectivo
        // del turno. La flecha va en la dirección que §2 permite —un módulo operativo puede depender de uno de
        // dominio— y no hay vuelta: `Finance` escucha eventos del KERNEL (D231), así que no conoce al POS.
        // `Pos` depende de `Catalog` (lee el artículo para congelar su precio y su nombre en la línea) y de `Floor`
        // (ocupa y libera mesas por `TableOccupancy`). Las dos son lecturas o llamadas a superficie pública, no
        // escrituras directas en tablas ajenas — el paso 7 empezó escribiendo `restaurant_tables.status` desde aquí y
        // el candado de fronteras lo destapó (D239).
        //
        // Y de `Customers` desde el paso 17: cobrar a crédito carga el saldo del cliente EN la transacción del pago,
        // porque si el cargo llegara tarde una cuenta podría quedar pagada sin cargar y el negocio habría regalado la
        // comida. `Customers` es dominio y `Pos` operaciones, así que la flecha va hacia abajo — la permitida.
        //
        // `Floor` es de la MISMA capa, y eso no es una excepción al grafo: la capa ordena de qué se puede depender
        // hacia abajo, no prohíbe que dos superficies operativas se conozcan. Lo que la regla exige es que la
        // dependencia esté DECLARADA y sea en un solo sentido, y `Floor` no depende de `Pos`.
        'Pos' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Punto de venta', 'depends_on' => ['Catalog', 'Customers', 'Finance', 'Floor']],
        // `Printing` depende de `Pos` por la FK de `print_jobs.pos_ticket_id` y porque arma el payload leyendo el
        // ticket. Podría evitarse metiendo el documento entero en el evento, y sería peor: duplicaría el papel en la
        // carga que viaja a la cola, con el riesgo de que diga algo distinto de lo que quedó registrado.
        //
        // La flecha va en un solo sentido: `Pos` no conoce a `Printing`. Lo que se comanda se anuncia por un evento del
        // kernel y este módulo escucha; el POS no sabe que existe una impresora.
        'Printing' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Impresión', 'depends_on' => ['Pos']],
        'Floor' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 4, 'label' => 'Salón y mesas', 'depends_on' => []],
        // Depende de `Catalog` porque una promoción apunta a artículos y categorías (FKs de `promotion_targets`). Es una
        // flecha HACIA ABAJO (operations → domain) y en un solo sentido: Catalog no conoce las promociones. La relación
        // con el POS NO es una dependencia: va por el probe `PromotionResolver` del kernel, así que ni Pos ni Promotions
        // se declaran mutuamente (D310). De `Organization` (sucursales) no hace falta declarar nada: es kernel.
        'Promotions' => ['layer' => 'operations', 'activatable' => false, 'iteration' => 6, 'label' => 'Promociones', 'depends_on' => ['Catalog']],

        // ---- Analítica -----------------------------------------------------
        'Reporting' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 7, 'label' => 'Reportes', 'depends_on' => []],
        'Dashboards' => ['layer' => 'analytics', 'activatable' => false, 'iteration' => 7, 'label' => 'Tableros', 'depends_on' => []],

        // ---- Activables por tenant ----------------------------------------
        'DigitalMenus' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 8, 'label' => 'Menús digitales', 'depends_on' => ['Catalog', 'Publishing']],
        // Lee artículos del Core (`Catalog`) y su publicación (`Publishing`); el stock lo consulta por una sonda del kernel
        // (`StockAvailabilityProbe`), así que no declara `Inventory`. Ninguno de esos módulos conoce a `Ecommerce`.
        'Ecommerce' => ['layer' => 'domain', 'activatable' => true, 'iteration' => 8, 'label' => 'Tienda en línea', 'depends_on' => ['Catalog', 'Publishing']],
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
