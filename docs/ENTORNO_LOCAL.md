# Entorno local de desarrollo — Windows + WampServer

Guía de la máquina de desarrollo. La configuración **objetivo** del proyecto está en
`.env.example`; este documento explica las diferencias del entorno local y cómo
cerrarlas.

---

## 1. Versiones verificadas

| Componente | Versión en esta máquina | Requisito |
|---|---|---|
| PHP (CLI, WampServer) | 8.3.6 ZTS | Laravel 13 exige `^8.3` |
| Composer | 2.8.5 | — |
| Node | 22.12.0 | 20+ |
| npm | 10.9.0 | — |
| MySQL | 8.3.0 | 8.x |
| Laravel | 13.25.0 | última estable |
| Pest | 4.7 | Pest 5 exige PHP 8.4; no aplica aún |

---

## 2. Ajuste obligatorio de MySQL

El MySQL de WampServer viene con:

```
default_storage_engine = MyISAM
```

Esto es **incompatible** con el proyecto: MyISAM no soporta transacciones ni llaves
foráneas, así que las tablas se crearían sin integridad referencial y sin un solo
mensaje de error.

El proyecto ya se protege por dos vías —`'engine' => 'InnoDB'` en
`config/database.php` y `NO_ENGINE_SUBSTITUTION` vía `'strict' => true`— y un test
verifica el resultado real
(`tests/Feature/FoundationSmokeTest.php`, "opera sobre MySQL con InnoDB forzado").

Aun así, **conviene corregir el servidor**. En `C:\wamp64\bin\mysql\mysql8.3.0\my.ini`,
sección `[mysqld]`:

```ini
default_storage_engine = InnoDB
```

Y reiniciar los servicios de Wamp. Verificación:

```bash
mysql -u root -e "SELECT @@default_storage_engine;"
```

---

## 3. Redis: pendiente en esta máquina

**Estado actual:** no hay servidor Redis (puerto 6379 cerrado) y PHP no tiene la
extensión `phpredis`.

La forma canónica del proyecto es Redis para sesión, cache y colas
(`ARQUITECTURA_MAESTRA` §12). Mientras no exista, el `.env` local usa drivers de
respaldo en un bloque **marcado explícitamente**:

```
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Esto es **deuda de entorno local, no una decisión de arquitectura**: `.env.example`,
`config/database.php` y `config/queue.php` siguen describiendo Redis como la forma
correcta.

### Qué se pierde mientras no haya Redis

- Las colas funcionan (driver `database`), pero no se ejercita el comportamiento real
  de Redis: latencia, bloqueos atómicos, `block_for`.
- Los locks de cache que usará la foliación sin huecos no se prueban contra el
  backend definitivo.
- No hay forma de medir profundidad de cola.

### Cómo instalarlo

**Opción A — Memurai (recomendada en Windows).** Implementación de Redis para Windows
que corre como servicio nativo. Es la que menos fricción tiene con WampServer.

**Opción B — WSL2 + Redis.** `wsl --install`, luego instalar `redis-server` en la
distribución y exponerlo en `127.0.0.1:6379`. Más fiel al servidor de producción; a
cambio hay que tener WSL corriendo.

En ambos casos, después de instalar:

1. Cambiar las tres líneas del bloque marcado del `.env` a `redis`.
2. Agregar `SESSION_CONNECTION=session` (base 2 de Redis; las sesiones no comparten
   espacio con las colas ni con la cache).
3. Borrar el bloque de advertencia del `.env`.
4. `php artisan config:clear`.

El cliente PHP es **predis** (paquete `predis/predis`), no la extensión. Funciona sin
compilar nada; en el VPS se recomienda `phpredis` por rendimiento, y el cambio es una
sola variable: `REDIS_CLIENT=phpredis`.

---

## 4. Bases de datos

Dos bases, ambas con `utf8mb4_0900_ai_ci` (decisión D58):

| Base | Uso |
|---|---|
| `comandia` | desarrollo |
| `comandia_testing` | suite de pruebas (`phpunit.xml`) |

Creación:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS comandia CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; CREATE DATABASE IF NOT EXISTS comandia_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

Las pruebas corren contra MySQL real, no SQLite (decisión D60). Si `comandia_testing`
no existe, la suite falla al conectar; no degrada silenciosamente.

---

## 5. Comandos de trabajo

Levantar el servidor de aplicación:

```bash
php artisan serve
```

Levantar Vite en modo desarrollo (recarga en caliente):

```bash
npm run dev
```

Compilar assets para producción:

```bash
npm run build
```

Suite completa de pruebas:

```bash
php artisan test
```

Sólo las reglas estructurales (scopes de tenant y fronteras de módulos):

```bash
php artisan test --testsuite=Architecture
```

Worker de colas con las cuatro colas en orden de prioridad:

```bash
php artisan queue:work --queue=critical,default,exports,printing
```

> El orden importa: Laravel drena de izquierda a derecha. `critical` —inventario y
> finanzas— siempre primero.

Formato de código:

```bash
./vendor/bin/pint
```

Negocio de demostración con catálogo, recetas, costos y modificadores (D133):

```bash
php artisan comandia:demo:seed
```

Entra con `demo@comandia.test` / `comandia`. Con `--fresh` borra el anterior y vuelve a sembrar.

Es lo que hace posible **verificar la interfaz en un navegador**, que es la única forma de encontrar
cierta clase de defecto: una pantalla vacía se ve igual que una pantalla rota. Después de sembrar
conviene vaciar la cola una vez, porque el costeo en cascada ocurre en segundo plano:

```bash
php artisan queue:work --stop-when-empty --queue=critical,default
```

---

## 6. Notas de PowerShell

- PowerShell 5.1 **no** soporta `&&` ni `||` para encadenar comandos. Usar `;` o
  `if ($?) { ... }`.
- Si `php` o `composer` no se encuentran, faltan en el `PATH`: agregar
  `C:\wamp64\bin\php\php8.3.6`.
- La política de ejecución puede bloquear scripts. Para la sesión actual:
  `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass`.

---

## 7. Lo que este entorno no puede verificar

Honestidad sobre los límites de la máquina de desarrollo:

| No verificable localmente | Dónde se verifica |
|---|---|
| Comportamiento real de las colas Redis | Al instalar Redis (§3) |
| Horizon y supervisión de workers | Iteración 11, VPS Linux (decisión D61) |
| Reverb / WebSockets bajo carga | Iteración 6 |
| Impresión ESC/POS a impresoras LAN | Iteración 4, con hardware real |
| Respaldo y **restauración probada** | Iteración 11 |

---

## 8. `SQLSTATE[HY000] 1615: Prepared statement needs to be re-prepared`

**Síntoma.** Aparece de golpe, en sitios distintos y sin patrón aparente: dos o tres pruebas de la suite en rojo con un
500 en un `DELETE` que siempre había funcionado, o `comandia:demo:seed --fresh` reventando al purgar. Se lee como un
defecto del código porque llega con un `PDOException` en medio de una consulta trivial.

**No es aleatorio, y no es del código.** Es un límite de capacidad de MySQL que empeora conforme el proyecto crece:

```bash
mysql -uroot -e "SHOW VARIABLES LIKE 'table_definition_cache'; SELECT COUNT(*) FROM information_schema.tables WHERE table_schema LIKE 'comandia%';"
```

En la iteración 4, paso 8, esa consulta daba **770 tablas en 10 esquemas** (`comandia` más las nueve bases que crea
`--parallel`) contra un `table_definition_cache` de **600**. Once pasos después, en el 19, iban **927** — y el fallo pasó
de aparecer en dos pruebas a aparecer en cinco. **Empeora con cada iteración**, porque cada una añade tablas. El caché no alcanza para todas las definiciones, así que
MySQL las desaloja; una sentencia preparada cuya tabla perdió su definición queda inválida, MySQL reintenta tres veces y
después lanza 1615.

Por eso aparece **justo después de migrar** y en las corridas en paralelo: son los dos momentos en que muchas
definiciones se tocan a la vez.

**Arreglo inmediato**, no destructivo y sin reiniciar nada:

```bash
mysql -uroot -e "FLUSH TABLES"
```

**Arreglo de fondo** — en `C:\wamp64\bin\mysql\mysql8.3.0\my.ini`, sección `[mysqld]`, y reiniciar el servicio de MySQL
desde el menú de WampServer:

```ini
table_definition_cache = 3000
```

3000 deja margen: con ~110 tablas por esquema, cubre la base de desarrollo más una corrida en paralelo de dieciséis
procesos sin volver a desalojar. **Es un cambio en la configuración del servidor de la máquina, así que lo hace una
persona**, no un comando del proyecto.

Mientras no se haga, `FLUSH TABLES` desbloquea el momento; el problema vuelve en la siguiente corrida grande — y cada
vez antes.

**Cómo reconocerlo sin dudar:** los fallos aparecen en pruebas que no se tocaron, en `DELETE` triviales, y **cambian de
prueba entre corridas**. Un defecto de código falla siempre en el mismo sitio; éste no. Si tras un `FLUSH TABLES` la
suite pasa entera, era esto.

---

## 9. Tiempo real: Reverb y por qué el piso no se mueve solo en desarrollo (Iteración 5)

El servidor de WebSockets es un **proceso aparte**. No lo levanta `php artisan serve` ni Vite:

```
php artisan reverb:start
```

Escucha en el puerto **8080** (`REVERB_SERVER_PORT`). Las variables ya están en `.env` y en `.env.example`.

### Lo que hay que saber antes de reportar un defecto

**Sin `queue:work`, por socket no llega nada.** La difusión va por cola a propósito —`ShouldBroadcast` y no
`ShouldBroadcastNow`— porque emitir dentro de la petición haría que un Reverb caído tumbara un cobro (D220, D300). En
desarrollo no corre ningún trabajador, así que los avisos se quedan encolados.

**Y aun así el piso se mantiene al día**, porque el respaldo de sondeo pide el piso completo cada diez segundos. La
pantalla dice cuál de los dos modos está usando, con un punto de color junto al título:

| Punto | Significa |
|---|---|
| Verde · «Al instante» | El socket está conectado y el sondeo está apagado |
| Naranja · «Cada 10 segundos» | No hay socket; la pantalla se refresca sola por sondeo |

Así que **«el piso no se actualiza al instante» no es un defecto** en una máquina de desarrollo sin cola: es lo
esperado. Para verlo en vivo hacen falta los tres procesos:

```
php artisan reverb:start
```

```
php artisan queue:work --queue=critical,default
```

Es la misma lección de D229 con el costeo —«en desarrollo no corre `queue:work`, así que ningún efecto asíncrono
ocurre»— en otra superficie. Antes de declarar un defecto de tiempo real, mirar `jobs`.

### En pruebas, la difusión está apagada

`phpunit.xml` fija `BROADCAST_CONNECTION=null`. Es lo correcto para que la suite no hable con ningún servidor, y tiene
una consecuencia que hay que recordar: **`NullBroadcaster::auth()` no consulta los canales**, así que una prueba de
autorización de canal escrita sin más pasa en verde autorice o no (D302). Las de `LiveFloorTest` apuntan a un
broadcaster real, purgan la conexión y **vuelven a cargar** `routes/channels.php` — las tres cosas hacen falta.
