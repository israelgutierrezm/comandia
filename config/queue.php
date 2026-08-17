<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión de colas por defecto
    |--------------------------------------------------------------------------
    |
    | La forma canónica del proyecto es `redis` (ARQUITECTURA_MAESTRA §6 y §12).
    | El nombre de la cola de cada job NO se configura aquí: se declara en el
    | propio job con `onQueue()` usando el catálogo de App\Support\Queue.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Conexiones
    |--------------------------------------------------------------------------
    |
    | Sólo tres: redis (canónica), database (respaldo de desarrollo cuando la
    | máquina no tiene Redis; ver docs/ENTORNO_LOCAL.md) y sync (pruebas).
    | Las demás del esqueleto de Laravel se eliminaron para que nadie las use
    | por accidente.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),

            // 90s es demasiado corto para exports pesados y demasiado largo para
            // los jobs críticos. Se afina por cola en la Iteración 11; hoy queda
            // el default de Laravel documentado como pendiente.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),

            'block_for' => null,

            // after_commit = true: un job de inventario o finanzas nunca debe
            // ejecutarse antes de que la transacción del documento origen esté
            // confirmada; si la transacción falla, el job no debe existir.
            // Esto es requisito directo de la idempotencia exigida en §6.
            'after_commit' => true,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Lotes de trabajos
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trabajos fallidos
    |--------------------------------------------------------------------------
    |
    | Persistidos en MySQL, no en archivo: un job crítico fallido (movimiento de
    | diario, descuento de inventario) es información operativa que hay que poder
    | consultar, reintentar y auditar.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
