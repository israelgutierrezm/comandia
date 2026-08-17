<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión por defecto
    |--------------------------------------------------------------------------
    |
    | Comandia opera exclusivamente sobre MySQL 8 / InnoDB (ADR-002). Las demás
    | conexiones que trae el esqueleto de Laravel fueron eliminadas a propósito:
    | correr pruebas o desarrollo sobre SQLite rompería la paridad de semántica
    | (FKs, DECIMAL, locks de foliación) que este proyecto necesita verificar.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Conexiones
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'comandia'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),

            // ---------------------------------------------------------------
            // ZONA HORARIA DE LA CONEXIÓN: UTC, SIEMPRE.
            //
            // ARQUITECTURA_MAESTRA §7 exige almacenamiento en UTC, y sin esta línea la regla
            // se cumple a medias: Laravel escribe UTC desde PHP, pero todo lo que genera la
            // BASE —`useCurrent()`, `CURRENT_TIMESTAMP`, `NOW()`— usa la zona de la sesión de
            // MySQL, que por defecto es `SYSTEM`.
            //
            // Medido en este entorno: MySQL devolvía 13:36 donde Laravel escribía 19:36. Seis
            // horas de diferencia dentro de la misma base, y precisamente en las tablas
            // inmutables —`audit_entries`, `tenant_status_transitions`— que declaran su
            // `created_at` con `useCurrent()`.
            //
            // El síntoma sería demoledor y difícil de ver: en una investigación, la entrada de
            // auditoría de un descuento aparecería seis horas antes de la venta a la que se
            // refiere. Y como la bitácora es inmutable, los datos mal fechados no se corrigen.
            //
            // Con esto, la zona horaria de la máquina deja de influir: la misma base da la
            // misma hora en el portátil del desarrollador y en el VPS.
            // ---------------------------------------------------------------
            'timezone' => env('DB_TIMEZONE', '+00:00'),

            'charset' => env('DB_CHARSET', 'utf8mb4'),

            // utf8mb4_0900_ai_ci: acento-insensible y caso-insensible. Necesario
            // para que la búsqueda de catálogo en español encuentre "cafe" al
            // teclear "café". Decisión D58 (docs/REGISTRO_DECISIONES.md).
            'collation' => env('DB_COLLATION', 'utf8mb4_0900_ai_ci'),

            'prefix' => '',
            'prefix_indexes' => true,

            // strict = true aplica ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES,
            // NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO y NO_ENGINE_SUBSTITUTION.
            // NO_ENGINE_SUBSTITUTION es la red de seguridad del punto siguiente:
            // si InnoDB no estuviera disponible, MySQL falla en vez de degradar.
            'strict' => true,

            // InnoDB FORZADO (regla no negociable de CLAUDE.md).
            // El MySQL de este entorno tiene default_storage_engine = MyISAM;
            // sin esta línea las tablas se crearían sin FKs ni transacciones y
            // el proyecto perdería en silencio su integridad referencial.
            'engine' => 'InnoDB',

            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tabla de control de migraciones
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Bases separadas por responsabilidad para que un `cache:clear` jamás pueda
    | vaciar la cola de trabajos: 0 = colas y locks, 1 = cache, 2 = sesiones,
    | 3 = broadcasting (Reverb, Iteración 6).
    |
    | Cliente: predis (PHP puro) porque WampServer no trae la extensión phpredis.
    | En el VPS se recomienda phpredis; el cambio es una sola variable de entorno.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'comandia')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
