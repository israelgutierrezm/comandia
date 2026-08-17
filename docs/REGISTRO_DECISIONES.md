# Registro de decisiones — continuación de D1–D57

Las decisiones D1 a D57 están en `ESPECIFICACION_MAESTRA.md` §8. Este archivo
continúa la numeración con las decisiones tomadas durante el diseño técnico
detallado.

Ninguna decisión de esta lista contradice una ADR vigente. Si alguna llegara a
hacerlo, el procedimiento es redactar la ADR que la reemplaza (ver
`docs/adr/README.md`), no anotarla aquí.

**Estados:** `Tomada` (dentro del alcance pre-aprobado de la Fase 0) ·
`Propuesta` (requiere aprobación explícita antes de implementarse).

---

## Fase 0 — Fundación del proyecto

### D58 — Colación `utf8mb4_0900_ai_ci` para toda la base
**Estado:** Tomada · **Ámbito:** `config/database.php`

La base y todas sus tablas usan `utf8mb4_0900_ai_ci`: acento-insensible y
caso-insensible.

**Por qué:** el catálogo es en español mexicano y el POS busca por teclado táctil a
toda prisa. Con la colación por defecto de Laravel (`utf8mb4_unicode_ci`, también
ai_ci pero de Unicode 5.2), y peor con una `_bin` o `_as_cs`, buscar `cafe` no
encontraría `Café`. La variante `0900` es la implementación nativa de MySQL 8 para
Unicode 9.0 y es además la más rápida.

**Deuda que genera:** si en el futuro alguna columna necesitara distinguir acentos o
mayúsculas —un folio, un hash, un slug con semántica estricta— hay que declararle
colación propia en su migración. Los identificadores técnicos deberían ser
`ascii_bin` explícitos por columna.

---

### D59 — Inertia como *shell*; los datos transaccionales por `/api/v1`
**Estado:** ⚠️ **Propuesta — requiere tu aprobación** · **Ámbito:** frontend completo

**Contradicción detectada.** El stack pedido para la Fase 0 incluye Inertia, pero
`ARQUITECTURA_MAESTRA` §8 declara la REST API "ciudadano de primera clase, la
consumen web y app por igual" y §9 describe el frontend Vue con `services (API
tipada)` y stores de Pinia. Inertia no consume una API REST: entrega props desde el
servidor en la misma respuesta que la página. Las dos cosas no se contradicen en el
papel, pero sí en la práctica: si el POS se construye sobre props de Inertia, la app
Flutter terminará consumiendo endpoints que la web no usa, y esos endpoints
—los menos ejercitados— serán los que fallen en producción.

**Propuesta:** Inertia entrega **sólo el shell**: autenticación, layouts, ruteo,
contexto operativo `{tenant, rol activo, sucursal activa, terminal}`, módulos activos
y permisos para pintar la navegación. **Todo dato de dominio** —órdenes, cuentas,
comandas, existencias, cortes, reportes— se consume desde `/api/v1` con Pinia y
servicios tipados.

**Alternativas:**

| | A favor | En contra |
|---|---|---|
| **A. Inertia como shell + API para datos** (recomendada) | Una sola API ejercitada por web y Flutter; el POS ya necesita estado en Pinia sincronizado por Reverb, que no es el modelo de Inertia; conserva la comodidad de Inertia donde de verdad ayuda (auth, layouts, guards) | Dos mecanismos de transporte en la misma app; hay que ser disciplinado sobre qué viaja por cada uno |
| **B. Inertia completo, API sólo para Flutter** | Desarrollo web más rápido; menos código de cliente | Dos superficies divergentes; la API de Flutter queda sub-ejercitada; el POS táctil con estado en vivo encaja mal con props del servidor |
| **C. SPA pura sobre `/api/v1`, sin Inertia** | Máxima coherencia con §8; una sola forma de obtener datos | Hay que construir a mano auth SPA, ruteo y guards que Inertia regala; más lento de arrancar y no era lo que pediste |

**Recomendación: A.** Si prefieres B o C, dilo y ajusto la Fase 0 en consecuencia —el
cambio es todavía barato: hoy sólo existe una página de bienvenida—.

**Implementado en Fase 0 conforme a A:** `App\Http\Middleware\HandleInertiaRequests`
comparte sólo identidad y nombre de la aplicación; `routes/api.php` existe con
prefijo `api/v1` desde el día uno.

---

### D60 — La suite de pruebas corre contra MySQL 8 real, no contra SQLite
**Estado:** Tomada · **Ámbito:** `phpunit.xml`, `tests/Pest.php`

Base dedicada `comandia_testing` en el mismo MySQL 8. Las conexiones `sqlite`,
`pgsql`, `sqlsrv` y `mariadb` se **eliminaron** de `config/database.php`.

**Por qué:** el proyecto tiene que verificar cosas que SQLite no reproduce: llaves
foráneas con la misma semántica, `DECIMAL(12,2)` y `DECIMAL(12,4)` exactos, colación
acento-insensible (D58), `SELECT ... FOR UPDATE` para la foliación sin huecos, y el
motor InnoDB forzado. Una suite verde en SQLite que falla en MySQL es peor que no
tener suite. Prioridad del proyecto: correctitud > velocidad de desarrollo.

**Deuda que genera:** la suite es más lenta y exige MySQL disponible en cualquier
máquina o pipeline que la ejecute. Se acepta.

---

### D61 — Horizon fuera de la Fase 0; operación local con `queue:work`
**Estado:** Tomada · **Ámbito:** dependencias, operación

`ARQUITECTURA_MAESTRA` §12 prevé Horizon en el VPS. **No se instaló en la Fase 0**
porque `laravel/horizon` requiere las extensiones `pcntl` y `posix`, que no existen
en PHP para Windows: agregarlo rompería `composer install` en el entorno de
desarrollo.

Local: `php artisan queue:work redis --queue=critical,default,exports,printing`.
Horizon entra en la **Iteración 11 (endurecimiento)**, cuando exista el VPS Linux,
junto con la supervisión de workers y las métricas de profundidad de cola.

**Deuda que genera:** hasta la Iteración 11 no hay panel de colas ni métricas de
profundidad en desarrollo. Los fallos se inspeccionan en la tabla `failed_jobs`.

---

### D62 — `app/Modules/Shared` para el kernel común; `app/Support` para infraestructura sin dominio
**Estado:** Tomada · **Ámbito:** estructura del proyecto

`ARQUITECTURA_MAESTRA` §2 enumera los módulos del shared kernel (Tenancy, Identidad,
Organización, Configuración, Auditoría, Notificaciones) pero no dice dónde vive lo que
es común **a varios de ellos**: el contexto inmutable del request, el servicio de
autorización por contexto, el global scope y el trait de tenant, el ULID público, la
base de modelos de dominio y la foliación. Ninguno pertenece a un módulo solo.

**Decisión:** se agrega `app/Modules/Shared` como séptimo módulo del kernel, con la
misma estructura canónica. Queda reservado `app/Support` para infraestructura sin
dominio (por ejemplo el enum `Queue`).

**Alternativa descartada:** meter el contexto en `Tenancy`. Se descartó porque el
contexto también carga rol activo (Identidad) y sucursal activa (Organización), y
`Tenancy` terminaría dependiendo de sus hermanos.

---

### D63 — Nombres de módulos en inglés, con tres traducciones que conviene fijar
**Estado:** Tomada · **Ámbito:** `app/Modules/`, `config/comandia.php`

El código va en inglés (CLAUDE.md). Tres nombres no son traducción directa y se fijan
aquí para que nadie los renombre a media obra:

| Módulo | Corresponde a | Por qué así |
|---|---|---|
| `Costing` | "Recetas y Costeo" | Las recetas existen en el producto para costear; `Recipes` dejaría fuera el historial de costos y el precio sugerido, que son la mitad del módulo |
| `Floor` | "Mesas y Layout" | `Tables` colisiona con "tablas" de base de datos en cada conversación técnica |
| `Pos` | "POS" | `Pos` y no `POS`: PSR-4 y las convenciones de Laravel prefieren `StudlyCase`, no siglas en mayúsculas |

---

### D64 — El registro de módulos es declarativo, no descubrimiento por disco
**Estado:** Tomada · **Ámbito:** `config/comandia.php`, `App\Providers\ModuleServiceProvider`

`ModuleServiceProvider` recorre la lista de `config/comandia.php`, no un `glob` de
`app/Modules`.

**Por qué:** una carpeta creada por error, a medio renombrar o sobreviviente de una
rama abandonada no debe convertirse en un módulo que carga rutas y migraciones sin
que nadie lo haya decidido. Un test estructural
(`tests/Architecture/ModuleBoundariesTest.php`) verifica que la lista y el disco
coincidan en ambas direcciones.

---

### D65 — Colas con `after_commit = true`
**Estado:** Tomada · **Ámbito:** `config/queue.php`

Los jobs se despachan **después** de confirmarse la transacción de base de datos.

**Por qué:** es requisito directo de las reglas de negocio. Un descuento de
inventario o un movimiento de diario no puede ejecutarse antes de que exista el
documento origen que lo justifica; y si la transacción se revierte, el job no debe
existir. Sin esto, la idempotencia por documento origen no alcanza: el job podría
buscar un documento que nunca llegó a existir.

---

---

## Iteración 1 — Shared Kernel (diseño aprobado 2026-08-17)

Las quince decisiones abiertas del diseño (P1–P15) quedaron resueltas. El razonamiento
completo y las alternativas descartadas están en
[`iteraciones/ITERACION_1_DISENO.md`](iteraciones/ITERACION_1_DISENO.md) §12.

### D66 — El nombre de una persona sin credenciales vive en `employee_profiles`
**Estado:** Tomada (P1) · **Ámbito:** `tenant_memberships`, `employee_profiles`

`tenant_memberships` **no** guarda el nombre. Precedencia de resolución: perfil de empleado
primero, usuario como respaldo. Invariante **I1**: `user_id IS NULL ⇒ existe perfil de
empleado`, impuesto en el servicio de aplicación y en las dos direcciones (tampoco se puede
borrar el perfil de una membresía sin credenciales).

**Por qué el perfil gana sobre el usuario:** `users` es global al SaaS y el tenant no puede
editarla; con la precedencia inversa, un nombre mal escrito en el perfil global se
imprimiría en las comandas de todos los restaurantes donde esa persona trabaja y ninguno
podría corregirlo.

**Deuda que genera:** mostrar un nombre cuesta dos `LEFT JOIN`; se resuelve con un único
resolutor del kernel para que ningún módulo escriba su propio `COALESCE`. Se pierde poder
dar a la misma persona un nombre distinto por tenant sin crearle perfil de empleado —no
rompe ningún requisito de los documentos maestros—.

### D67 — La autorización por PIN evalúa la unión de roles del autorizador
**Estado:** Tomada (P2) · **Ámbito:** servicio de autorización por PIN · **→ [ADR-008](adr/ADR-008-autorizacion-por-pin-excepcion-rol-activo.md)**

Quien teclea su PIN no tiene sesión y por tanto no tiene rol activo. Es una **excepción
acotada a D9**, que es regla no negociable, y por eso está registrada en una ADR con sus
cinco límites inseparables. La excepción vive en un solo endpoint y un test estructural
falla si aparece en cualquier otro lugar.

### D68 — `roles.tenant_id` NOT NULL; el super admin fuera de Spatie
**Estado:** Tomada (P3) · **Ámbito:** tablas de Spatie, `users`

Sin roles globales, para que la Regla A de ADR-002 no tenga excepciones. El super admin usa
`users.is_super_admin` y su propia capa de autorización, que ya vive fuera del dominio.

### D69 — El tenant de un token de API viaja en la credencial
**Estado:** Tomada (P4) · **Ámbito:** `personal_access_tokens`

Columnas `tenant_id` y `membership_id` NOT NULL. Un token no puede cruzar tenants ni por
error, y revocar el acceso de una persona a un tenant es borrar sus tokens de ese tenant.
El rol activo y la sucursal activa **no** van en el token: siguen en headers validados,
porque el tenant es propiedad de la credencial y no se negocia, mientras el rol y la
sucursal son elecciones legítimas entre lo ya concedido. Cierra el pendiente que dejó la
Fase 0 sobre esta tabla.

### D70 — Seis estados del tenant
**Estado:** Tomada (P12) · **Ámbito:** `tenants.status`

`pending_activation`, `active`, `suspended`, `read_only`, `pending_deletion`, `cancelled`.
Tener `read_only` desde el día uno evita rehacer el middleware el día que se defina la
política de impago.

### D71 — Los seis roles plantilla nacen ahora; su reparto operativo se afina por iteración
**Estado:** Tomada (P13) · **Ámbito:** seeder de roles

Propietario, Gerente, Cajero, Mesero, Mesero con cobro y Almacenista se crean en la
Iteración 1. El reparto de los permisos de POS, inventario y finanzas se revisa al construir
cada módulo, porque hoy se estaría decidiendo si un mesero puede cancelar un platillo
comandado sin haber visto el flujo en pantalla. Cada ajuste se registra.

### D72 — El catálogo de permisos se siembra completo desde la Iteración 1
**Estado:** Tomada (P14) · **Ámbito:** seeder de permisos

Es un catálogo cerrado del sistema y §4.2 ya define que los permisos de módulos inactivos
no se muestran al tenant. Cada iteración puede agregar, renombrar o retirar permisos **de su
propio módulo**, nunca de otro; un permiso retirado se elimina del catálogo y de los roles
que lo tuvieran en la misma migración.

### D73 — `document_sequences` se construye en la Iteración 1
**Estado:** Tomada (P15) · **Ámbito:** módulo `Shared`

Es infraestructura del kernel (§7) y su mecanismo de concurrencia se puede probar con
transacciones simultáneas reales desde ya. Dejarla para la Iteración 4 metería un lock
delicado en la iteración más grande del proyecto y bajo presión de entrega.

### D74 a D80 — Decisiones tomadas por recomendación

| | Decisión | Ámbito |
|---|---|---|
| **D74** | Alcance por almacén **diferido a la Iteración 3**. Hasta entonces el alcance efectivo es "los almacenes de mis sucursales" y el almacén central se protege con permiso, no con alcance. Deuda declarada (P5) | `membership_branch_scopes` |
| **D75** | Historial de estados del tenant en **tabla propia** `tenant_status_transitions`, inmutable. La bitácora se archiva a los 12 meses (D47) y una disputa de cobro puede llegar después (P7) | Tenancy |
| **D76** | Nombre por partes como `first_name` / `paternal_surname` / `maternal_surname`. El código va en inglés, pero son conceptos del registro civil mexicano y `second_last_name` invita a llenarlos al revés (P10) | `users`, `employee_profiles` |
| **D77** | CURP, RFC y NSS **en claro**, con permiso dedicado y lectura auditada. El RFC tiene que ser buscable para CFDI y la unicidad por tenant verificable por índice; cifrar exigiría *blind indexes*. Se revisa al construir nómina (P6) | `employee_profiles` |
| **D78** | Configuración en **dos tablas** (`tenant_settings`, `branch_settings`). En MySQL un índice único no impide duplicados cuando una columna es NULL, así que una tabla única no podría garantizar una sola fila por llave a nivel tenant sin un centinela que rompe la FK (P8) | Configuration |
| **D79** | Valor de configuración en **una columna `VARCHAR(500)`** tipada por el catálogo en código, que ya es la autoridad del tipo y valida en la escritura (P9) | Configuration |
| **D80** | **Sin soft deletes** en el kernel. El ciclo de vida se modela con `status`: hay documentos históricos apuntando a sucursales y almacenes, y un `deleted_at` conviviendo con índices únicos que ya no distinguen es una trampa (P11) | todo el kernel |

---

## Iteración 1 — hallazgos durante la implementación

### D81 — La llave de cache de permisos de Spatie es por tenant
**Estado:** Tomada · **Ámbito:** `IdentityServiceProvider`, `TenantContext`

`Role` lleva global scope de tenant (ADR-002), pero el registrador de Spatie construye su
cache con `Permission::with('roles')` y la guarda bajo **una sola llave**. Con el scope
aplicado, la cache escrita en la petición del tenant A se reutilizaría en la del tenant B,
a la que le faltarían sus roles.

El fallo habría sido **silencioso y no determinista**: permisos denegados sin razón
aparente, dependiendo de qué tenant calentó la cache primero.

La llave de cache y el *team* de Spatie se mueven junto con el contexto mediante un
mecanismo de notificación en `TenantContext`, así que no dependen de que nadie recuerde
llamarlos. Se usa `clearPermissionsCollection()` y **no** `forgetCachedPermissions()`: el
segundo borra la cache persistente y llamarlo en cada cambio de contexto habría vaciado
Redis en cada petición, dejando la cache sin efecto alguno.

### D82 — `ProvisionTenantRoles` escribe el pivote de permisos directamente
**Estado:** Tomada · **Ámbito:** `ProvisionTenantRoles`

Seis roles con hasta ~130 permisos son ~500 filas de pivote. `syncPermissions()` invalida
la cache de Spatie en cada llamada, así que la verificación siguiente recarga el catálogo
completo desde la base, seis veces por alta de tenant.

**Medido:** ~2 s por aprovisionamiento con `syncPermissions()`; decenas de milisegundos
escribiendo el pivote en una sola inserción e invalidando la cache una vez al final. La
suite completa pasó de 54,7 s a 30,5 s con las mismas aserciones.

Importa fuera de las pruebas: el alta de tenant en autoservicio (D6) ocurre con un humano
esperando la respuesta.

Las filas escritas son exactamente las que escribiría Spatie; lo que se evita es su ciclo
de invalidación. El servicio falla ruidosamente si el catálogo no está sembrado, en lugar
de crear roles a medias.

### D83 — Endpoint `GET /api/v1/context`
**Estado:** Tomada · **Ámbito:** módulo `Shared`

No estaba en el diseño aprobado y se añadió porque hacía falta: es la respuesta a "¿quién
soy y qué puedo hacer aquí?" que consumen por igual la SPA —al arrancar el shell y al
cambiar de rol o sucursal— y la app Flutter. Es la simetría que ARQUITECTURA_MAESTRA §8
pide, y además permite probar el middleware de contexto de punta a punta contra el endpoint
real en lugar de contra una ruta de juguete.

No recibe parámetros: todo lo resolvió el middleware. Que no tenga entrada es la prueba de
que el contexto no se negocia con el cliente.

**Nota operativa descubierta al probarlo:** para que una petición a `/api/v1` se autentique
por cookie de sesión, Sanctum exige que venga de un dominio declarado *stateful*, y lo
determina por las cabeceras `Referer` u `Origin`. Un navegador siempre las manda; el cliente
de pruebas de Laravel no. Está resuelto con el helper `TestCase::actingAsSpa()`, y queda
anotado porque el mismo detalle aparecerá al configurar CORS en el despliegue.

---

## Pendientes que no son decisiones de Fase 0

Se listan para no perderlos; se resuelven en la iteración indicada.

| Pendiente | Iteración | Nota |
|---|---|---|
| `users` sigue siendo la migración base de Laravel y `App\Models\User` sigue en `app/Models` | 1 | El diseño del kernel lo mueve a `App\Modules\Identity` con nombre por partes (§4.1). La lista de excepciones de `TenantScopeTest` se actualiza con el nuevo FQCN |
| El detector de scopes compara por **nombre corto** `TenantScope` | 1 | Al fijarse el namespace del kernel, endurecer a FQCN exacto para que un `TenantScope` casero en otro namespace no pase |
| `personal_access_tokens` es la migración estándar de Sanctum | 1 | Un token debería llevar el contexto operativo (tenant, y terminal si aplica). Se decide con el diseño del kernel |
| Traducciones `es_MX` de validación | 1 | `APP_LOCALE=es_MX` ya está puesto; los mensajes en español llegan con los primeros Form Requests |
| `retry_after` por cola | 11 | 90 s es corto para `exports` y largo para `critical` |
| Redis no instalado en la máquina de desarrollo | — | Deuda de entorno, no de proyecto. Ver `docs/ENTORNO_LOCAL.md` |
