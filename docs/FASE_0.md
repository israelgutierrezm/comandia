# Fase 0 — Fundación del proyecto

**Estado:** completada y verificada.
**Alcance:** fundación técnica únicamente. **Cero tablas de dominio, cero modelos de
negocio, cero módulos funcionales.** La primera migración de dominio se escribe en la
Iteración 1 y sólo con el diseño aprobado.

---

## 1. Stack instalado

| Componente | Versión | Nota |
|---|---|---|
| Laravel | 13.25.0 | última estable; exige PHP `^8.3` |
| PHP | 8.3.6 | WampServer |
| MySQL | 8.3.0 | InnoDB forzado en la conexión |
| Sanctum | 4.3 | sesión SPA para Vue, tokens para Flutter |
| Spatie Laravel Permission | 8.3 | **teams activado**, llave `tenant_id` |
| Predis | 3.6 | cliente Redis en PHP puro (sin `phpredis` en Windows) |
| Inertia (servidor) | 3.3 | ver decisión D59 |
| Vue | 3.x | con `@vitejs/plugin-vue` y `@inertiajs/vue3` |
| Vite | 8.2 | Tailwind 4 vía `@tailwindcss/vite` |
| Pest | 4.7 | Pest 5 exige PHP 8.4; no aplica todavía |

**No instalado a propósito:** Horizon (requiere `pcntl`/`posix`, inexistentes en PHP
para Windows — decisión D61) y Reverb (Iteración 6).

---

## 2. Estructura modular

`app/Modules/` con los 21 módulos del mapa de `ARQUITECTURA_MAESTRA` §2. Los seis del
shared kernel más `Shared` tienen ya las carpetas canónicas; el resto son carpetas
reservadas con su archivo `.module.md` describiendo alcance, capa, iteración y reglas
de frontera.

```
app/Modules/{Modulo}/
├── Domain/          # entidades, reglas, value objects, máquinas de estado
├── Application/     # servicios de caso de uso, DTOs
├── Infrastructure/  # modelos Eloquent, repositorios, adaptadores
├── Http/            # Controllers, Requests, Resources, Routes
├── Events/ · Listeners/ · Jobs/
├── Providers/
└── database/        # migrations y seeders del módulo
```

| Capa | Módulos |
|---|---|
| kernel | `Shared` `Tenancy` `Identity` `Organization` `Configuration` `Audit` `Notifications` |
| domain | `Catalog` `Costing` `Inventory` `Purchasing` `Finance` `Customers` |
| operations | `Pos` `Printing` `Floor` `Promotions` |
| analytics | `Reporting` `Dashboards` |
| activables | `DigitalMenus` `Ecommerce` |

`config/comandia.php` es el **registro declarativo** de módulos: capa, activable por
tenant e iteración. `App\Providers\ModuleServiceProvider` recorre ese registro —no el
disco (decisión D64)— y engancha, si existen, las migraciones del módulo, su service
provider y sus tres archivos de rutas (`api.php` con prefijo `api/v1`, `web.php` y
`public.php` para superficies sin autenticación).

---

## 3. Configuración con consecuencias

### InnoDB forzado
`config/database.php` declara `'engine' => 'InnoDB'` y `'strict' => true`. El MySQL de
este entorno tiene `default_storage_engine = MyISAM`; sin esas dos líneas las tablas
se crearían sin transacciones ni llaves foráneas y sin ningún error visible. Un test
verifica el motor real de las tablas creadas.

Se **eliminaron** las conexiones `sqlite`, `mariadb`, `pgsql` y `sqlsrv` (decisión
D60): correr el proyecto o sus pruebas sobre otro motor rompería la paridad de
semántica que hay que verificar.

### Las cuatro colas
`App\Support\Queue` es el catálogo cerrado —`critical`, `default`, `exports`,
`printing`— con la justificación de cada una escrita en el propio enum. Prohibido
escribir el nombre de una cola como cadena literal.

`config/queue.php` usa `after_commit = true` (decisión D65): un job de inventario o
finanzas no puede ejecutarse antes de que exista el documento que lo justifica.

Trabajos fallidos en MySQL, no en archivo: un movimiento de diario fallido es
información operativa que hay que poder consultar, reintentar y auditar.

### Spatie con teams = tenant
`config/permission.php`: `'teams' => true` y `'team_foreign_key' => 'tenant_id'`. El
valor tiene que estar puesto **antes** de correr la migración de Spatie, que se crea
en la Iteración 1.

La migración de Spatie **no se ejecutó** en la Fase 0: sus tablas son del kernel y
entran con el diseño aprobado de la Iteración 1.

### API versionada
`bootstrap/app.php` registra `routes/api.php` con prefijo `api/v1` desde el día uno.
El único endpoint es el de identificación de la API.

### Endurecimiento de Eloquent
`AppServiceProvider` activa fuera de producción `preventLazyLoading`,
`preventSilentlyDiscardingAttributes` y `preventAccessingMissingAttributes`. Los tres
convierten en error visible fallos que en este dominio se pagan caro: un N+1 que
degrada el POS en hora pico, un `fill()` con una columna mal escrita que descarta el
dato en silencio, y la lectura de un atributo no cargado que devuelve `null` y produce
un cobro incorrecto.

---

## 4. Pruebas

Tres suites (`phpunit.xml`):

| Suite | Contenido |
|---|---|
| `Unit` | dominio puro |
| `Feature` | casos de uso por endpoint e integración evento → listener |
| `Architecture` | reglas estructurales |

Corren contra **MySQL real** (`comandia_testing`), no SQLite (decisión D60). Cache y
sesión en memoria; colas en `sync` salvo que un test declare lo contrario.

### Test estructural de scopes de tenant

`tests/Architecture/TenantScopeTest.php` es la red de seguridad exigida por ADR-002.
`Tests\Support\DomainModelDiscovery` recorre **todo `app/`** —no sólo las carpetas de
modelos— porque un modelo Eloquent puesto por descuido en cualquier otro lugar
seguiría consultando la base sin acotar, y ese es justo el caso a cazar.

En Fase 0 no hay modelos de dominio, así que la verificación principal recorre un
conjunto vacío. **Por eso el test incluye tres autoverificaciones** que no son
opcionales: un doble de prueba con scope debe pasar, un doble sin scope debe reprobar,
y `App\Models\User` debe aparecer en el descubrimiento. Sin ellas, el test estaría
verde por estar ciego —que es la peor forma de tener una red de seguridad—.

Lista de excepciones justificadas: hoy sólo `App\Models\User`, por ser identidad
global del SaaS (§4.1, capa 1). Toda alta en esa lista es una decisión de
arquitectura.

### Test de fronteras de módulos

`tests/Architecture/ModuleBoundariesTest.php` verifica que el registro de módulos y las
carpetas en disco coincidan **en ambas direcciones**, y que ningún archivo del shared
kernel referencie un módulo de dominio (`ARQUITECTURA_MAESTRA` §2, regla 1).

---

## 5. Verificación ejecutada

| Comprobación | Resultado |
|---|---|
| `php artisan migrate` (migraciones base + Sanctum) | 4 migraciones aplicadas |
| Motor real de las 10 tablas creadas | **InnoDB**, colación `utf8mb4_0900_ai_ci` |
| `npm run build` | Vite 8.2.1, 562 módulos, sin errores |
| `php artisan test` | **16 pruebas, 16 verdes** |
| `php artisan serve` + `GET /` | 200, shell de Inertia con Vue montado |
| `GET /api/v1` | 200 JSON |
| `GET /up` | 200 |

---

## 6. Lo que NO se hizo, deliberadamente

- Ninguna tabla de dominio, ningún modelo de negocio, ningún módulo funcional.
- La migración de Spatie no se ejecutó (entra en la Iteración 1).
- `App\Models\User` sigue donde lo dejó Laravel; se mueve a `Identity` en la
  Iteración 1 con nombre por partes.
- Sin Horizon (D61), sin Reverb, sin traducciones `es_MX` de validación —llegan con los
  primeros Form Requests—.

Los pendientes están listados en `docs/REGISTRO_DECISIONES.md`.

---

## 7. Decisiones que la Fase 0 generó

D58 a D65 en `docs/REGISTRO_DECISIONES.md`. Una está **pendiente de tu aprobación**:

> **D59 — Inertia como shell, datos transaccionales por `/api/v1`.** El stack pedido
> incluye Inertia, pero `ARQUITECTURA_MAESTRA` §8 declara la REST API ciudadano de
> primera clase consumida por web y app por igual. Se implementó la variante mínima y
> reversible; la decisión de fondo requiere tu visto bueno.
