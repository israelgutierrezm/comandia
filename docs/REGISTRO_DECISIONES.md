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

### D85 — La conexión a MySQL declara UTC explícitamente
**Estado:** Tomada · **Ámbito:** `config/database.php` · **Corrige un defecto real**

`ARQUITECTURA_MAESTRA` §7 exige almacenamiento en UTC, y hasta este punto la regla se cumplía
**a medias**: Laravel escribía UTC desde PHP, pero todo lo que generaba la base —`useCurrent()`,
`CURRENT_TIMESTAMP`, `NOW()`— usaba la zona de la sesión de MySQL, que por defecto es `SYSTEM`.

**Medido en este entorno:** MySQL devolvía `13:36` donde Laravel escribía `19:36`. Seis horas de
diferencia dentro de la misma base, y precisamente en las dos tablas **inmutables**
—`audit_entries` y `tenant_status_transitions`— que declaran su `created_at` con `useCurrent()`.

El síntoma habría sido demoledor y difícil de ver: en una investigación, la entrada de auditoría
de un descuento aparecería seis horas antes de la venta a la que se refiere. Y como la bitácora es
inmutable, los datos mal fechados **no se corrigen**.

Se añadió `'timezone' => '+00:00'` a la conexión, así que la zona de la máquina deja de influir:
la misma base da la misma hora en el portátil del desarrollador y en el VPS. Y el trait
`Immutable` escribe además `created_at` desde PHP, para no depender de que dos relojes coincidan
y para que el modelo recién creado tenga la fecha disponible sin releerla.

`tests/Feature/Shared/DatabaseTimezoneTest.php` falla si la configuración se rompe, que es
exactamente cuando hace falta.

### D84 — El autorizador se identifica con código de empleado + PIN
**Estado:** Tomada (P16) · **Ámbito:** `PinAuthorizationService`

La Especificación §4.2 dice que el PIN identifica al actor, pero identificar **por PIN
solo** obliga a comparar el PIN teclado contra el hash bcrypt de cada membresía del tenant:
a coste 12 son ~250 ms por comparación, así que con veinte empleados serían ~5 segundos por
autorización, en hora pico y con el cliente delante.

Con el código de empleado la búsqueda es por índice único y hay **una sola** comparación de
hash. El código funciona además como segundo factor débil.

**Alternativa descartada y por qué:** indexar un HMAC del PIN habría permitido teclear sólo
cuatro dígitos, pero §10.4 exige que la llave de la aplicación sea **rotable**, y rotarla
invalidaría todos los hashes de búsqueda a la vez — la rotación se convertiría en una
migración de datos con los PIN irrecuperables. También obligaría a que ningún PIN se
repitiera dentro del tenant.

**Consecuencia operativa que hay que reflejar en la UI:** quien no tiene código de empleado
no puede autorizar. Es coherente —autorizar es un acto identificado— pero el alta de personal
debe pedir el código a quien vaya a tener capacidad de autorización.

**No requirió migración:** `employee_code` ya existía en el diseño aprobado.

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

## Iteración 1 — hallazgos al construir y probar la UI de administración

Todo lo de esta sección salió de **abrir las pantallas en un navegador**, no de la suite. Vale la
pena decirlo así: la suite estaba en verde con estos defectos puestos, y varios de ellos son de la
clase que una prueba de backend no puede ver.

### D86 — D59 (frontera de Inertia) queda **tomada** en su variante A
**Estado:** Tomada · **Ámbito:** todo el frontend

Se implementó la UI de administración bajo la variante A —Inertia entrega el **shell**, todos los
datos de dominio vienen de `/api/v1`— y funciona de punta a punta. La decisión deja de ser propuesta.

Lo que la confirma en la práctica: cada pantalla de administración ejercita exactamente los endpoints
que consumirá la app Flutter. Si la web hubiera recibido props del servidor, esos endpoints serían
los menos probados del sistema y los primeros en fallar en producción.

`HandleInertiaRequests` comparte sólo identidad, contexto operativo, módulos activos y permisos del
rol activo, y hay un test que falla si aparecen datos de dominio en las props del shell.

### D87 — Las etiquetas en español de módulos, enumerados y acciones viven en el BACKEND
**Estado:** Tomada · **Ámbito:** `config/comandia.php`, `SettingCatalog`, `AuditAction`

La pantalla de configuración y el editor de roles se autoconfiguran desde la API (idea de ADR-006):
agregar una llave de configuración o un permiso los hace aparecer sin tocar el frontend. Eso obliga a
que el **texto para mostrar** también venga de la API.

Estaban saliendo identificadores en inglés en crudo: encabezados `CONFIGURATION` y `COSTING`,
opciones `multiple_5`, `on_pickup`, `branch_default`, y acciones `organization.branch_created`. La
tentación era un mapa de traducciones en Vue; se descartó porque habría obligado a editar el frontend
por cada llave, permiso o acción nueva — es decir, habría anulado la ventaja del diseño
autoconfigurable y garantizado que lo siguiente saliera otra vez en inglés.

Concretamente:

- `config/comandia.php` gana un `label` por módulo. Es la fuente de verdad de qué módulos existen,
  así que es donde corresponde.
- `SettingDefinition` gana `allowedLabels`; el recurso expone `allowed_options` con `{value, label}`
  **junto a** `allowed_values`, que se conserva: el cliente compara VALORES, nunca texto traducible.
- `AuditAction` gana `labels()`, junto a las constantes y no en un mapa central, por lo mismo que el
  catálogo de acciones está distribuido (§2): cuando `Pos` declare las suyas, traerá sus etiquetas.

Tres candados nuevos: todo módulo declara `label`, todo enumerado con más de una opción real declara
etiquetas, y toda acción declarada tiene etiqueta (con la verificación inversa, que detecta una
constante renombrada sin actualizar el mapa).

### D88 — Un actor explícito para las acciones auditadas ANTERIORES al contexto
**Estado:** Tomada · **Ámbito:** `AuditLogger`, flujo de identidad

`AuditLogger` toma el actor del contexto de la petición, y eso es lo correcto: así ningún llamador
puede omitirlo. Pero el **inicio de sesión ocurre antes de que exista contexto** —la autenticación es
global al SaaS y el negocio se resuelve después (§4.1)—, así que el asiento salía atribuido a
«Sistema». Justo el asiento cuyo único propósito es nombrar a quien entró.

`log()` acepta un parámetro `actor` que sólo usa el flujo de identidad (entrar, cambiar de negocio,
intento fallido). El resto del sistema no lo pasa y no debe pasarlo.

La prueba anterior comprobaba únicamente `exists()` y por eso pasaba en verde con el defecto puesto.
Ahora verifica **quién**. Sin actor, además, el reporte de «cinco intentos fallidos sobre esta
persona» (§6.7) no podría agrupar por persona.

### D89 — El contexto expone `assigned_roles`
**Estado:** Tomada · **Ámbito:** módulo `Shared`

El selector de rol activo del shell sólo podía ofrecer el rol ya activo, porque no existía forma de
saber cuáles tiene asignados la persona: un selector de una sola opción, inútil precisamente en el
producto donde el rol activo decide todo (D9).

**No contradice D9, y la distinción es la que importa:** listar los roles asignados no es sumar sus
permisos. Los permisos que viajan siguen siendo los de UN rol —el activo— y el servidor revalida la
elección contra el pivote al recibir `X-Role`. Se consulta la relación `roles()` de Spatie, que es
pertenencia y está permitida; nunca `hasRole()` ni `getAllPermissions()`.

### D90 — La sucursal elegida se recuerda en la membresía
**Estado:** Tomada · **Ámbito:** `ResolveTenantContext`

La cascada de sucursal activa es header → última usada → la única del alcance, y el peldaño
intermedio estaba **muerto**: `last_active_branch_id` existía, el middleware la leía y nadie la
escribía nunca. Con una sola sucursal no se nota; en cuanto se creó la segunda desde la interfaz,
la persona aterrizaba sin sucursal activa en cada navegación.

Ahora el middleware la persiste cuando el cliente la elige por header, y sólo si cambió —la
comparación evita un UPDATE por petición—. Un intento **rechazado** no deja rastro: si lo dejara, un
403 podría alterar el contexto de la petición siguiente.

### D91 — La bitácora NO expone el ID de la entidad auditada
**Estado:** Tomada (parcial) · **Ámbito:** `AuditEntryResource`

`auditable.id` era la PK autoincrement interna, y "nunca exponer IDs secuenciales" es regla de datos
no negociable. Se estaba filtrando: la pantalla mostraba «Branch #2». Se retiró del recurso.

**Consecuencia honesta:** la columna «sobre qué» identifica hoy el TIPO de entidad y no la entidad
concreta. Ver el pendiente de abajo.

---

## Iteración 2 — decisiones aprobadas y hallazgos de los pasos 1 a 4

### D92 — El grafo de dependencias entre módulos se declara y se impone (P1)
**Estado:** Tomada · **Ámbito:** `config/comandia.php`, candado de fronteras

`Costing` lee `Catalog` —`recipe_lines.component_article_id` es FK a `articles`, y no hay forma de
evitarlo sin duplicar el catálogo— y **nunca le escribe**. Aceptar un precio sugerido pasará por el
servicio de `Catalog`, dueño de `articles.base_price` y del historial de precios. `Catalog` no conoce
`Costing`.

Lo que hace que esto sea arquitectura y no buena intención: cada módulo declara `depends_on` en el
registro y `ModuleBoundariesTest` **impone el grafo**. Antes el candado sólo vigilaba que el kernel no
dependiera de módulos de dominio; dos módulos de dominio podían acoplarse en **ambos** sentidos sin que
nada protestara. Verificado a mano quitando la declaración: el candado falla y nombra los tres archivos
donde aparece la referencia.

### D93 — Unicidad con columna NULL: columna generada `STORED` (P2)
**Estado:** Tomada · **Ámbito:** `article_categories`, patrón del proyecto

En MySQL un índice único no deduplica NULL, así que `unique(tenant, parent_id, name)` permitiría dos
categorías raíz con el mismo nombre. Se resuelve con `parent_key = COALESCE(parent_id, 0)` generada y
almacenada, y el único sobre ella: la unicidad pasa a ser **estructural** en lugar de una validación que
una condición de carrera puede saltarse, y `parent_id` conserva su FK real.

**No se toca D78** (configuración en dos tablas): cambiar algo que funciona para ganar consistencia
estética no lo vale. Queda escrito que si la configuración se vuelve a tocar, migra a este patrón.

### D94 — Los costos unitarios van en `DECIMAL(12,4)` (P3)
**Estado:** Tomada · **Ámbito:** ARQUITECTURA_MAESTRA §7

Excepción **declarada y acotada** a "dinero = `DECIMAL(12,2)`". Un costo unitario no es un monto, es un
monto *por unidad*: el gramo de sal cuesta $0.000012 y a dos decimales es cero, con lo que toda receta
que use sal costaría cero.

Alcance: `article_costs.unit_cost`, `article_current_costs.unit_cost`, y —cuando lleguen—
`price_changes.suggested_price` y `unit_cost_at_change`. Los montos, incluido `articles.base_price`,
siguen en `(12,2)`. **Escrita en §7** para que nadie la "corrija" de buena fe.

Consecuencia técnica: la aritmética de costeo usa `bcmath` y no `float`, y las columnas decimales
**no se castean** en los modelos. Con `float`, el error de cada paso se acumula nivel por nivel de
sub-receta y puede llegar al segundo decimal del costo de un platillo — y un costo que "casi" cuadra es
peor que uno que no cuadra, porque nadie lo investiga. Hay prueba de ida y vuelta con un factor de ocho
decimales, y otra de que la notación científica de PHP (`1.0E-5`, que `bcmath` leería como 1) no
convierte una cantidad diminuta en una cien mil veces mayor.

### D95 — El costo vigente es una proyección, en tabla propia de `Costing` (P4)
**Estado:** Tomada, **con una corrección respecto al diseño aprobado** · **Ámbito:** `Costing`

La verdad es la última fila de `article_costs`; `article_current_costs` es caché. Mismo patrón que la
especificación usa en inventarios: "kardex como fuente de verdad; existencia como acumulado" (§6.2).
Las tres condiciones aprobadas están cumplidas: misma transacción, `comandia:costs:rebuild` (con
`--check` que falla si hay divergencias, para colgarlo de un chequeo periódico) y prueba que fuerza la
divergencia y comprueba que se detecta y se arregla.

**La corrección:** el diseño puso las columnas en `articles`, y eso **contradice P1** —aprobada en el
mismo mensaje—, porque una FK de `articles` a `article_costs` es una referencia de `Catalog` a
`Costing`. Las dos decisiones eran incompatibles y no lo advertí al escribir el diseño. La resolución
conserva la sustancia de ambas: sigue habiendo proyección (evitar N consultas anidadas, que era el
argumento de P4) y `Catalog` sigue sin conocer a `Costing`. Se pierde que viva en la misma fila, que
era incidental: un JOIN a una tabla 1:1 resuelve lo mismo en una consulta.

**Consecuencia visible que hay que aceptar:** el recurso del artículo **no** trae costo. Una pantalla
de catálogo con columna de costo hace dos llamadas (`GET /articles` y `GET /articles/{ulid}/cost`).

### D96 — La unidad base de un artículo es inmutable (I6, forma estricta)
**Estado:** Tomada · **Ámbito:** `Catalog`

El diseño decía "no cambia si el artículo tiene costos, recetas o movimientos". P1 hace esa versión
**imposible de imponer** desde `Catalog`: averiguar si tiene costos sería preguntarle a `Costing`, y si
tiene movimientos, a `Inventory`, que no existe. La regla que este módulo sí puede imponer
correctamente es la estricta: no cambia nunca. La salida está en el mensaje de la excepción —archivar y
capturar de nuevo— y la UI tiene que advertirlo al elegir la unidad, porque es una decisión
irreversible como el código de una sucursal.

### D97 — Las unidades se siembran al dar de alta un negocio, por evento de dominio
**Estado:** Tomada · **Ámbito:** `Tenancy` (evento), `Catalog` (listener)

`articles.base_unit_id` es NOT NULL, así que un tenant con cero unidades **no puede capturar ni un
artículo**: el primer minuto del producto sería un formulario que obliga a inventar el sistema métrico.
Se siembran cinco (g, kg, ml, l, pza) y el tenant puede desactivarlas o agregar las suyas.

`ProvisionTenant` vive en el kernel y el kernel **no puede depender de un módulo de dominio**, así que
emite `TenantProvisioned` sin saber quién escucha y `Catalog` decide que le importa (§2, regla 3). Es
el primer evento de dominio del proyecto y el primer uso real del mecanismo.

### D98 — Reparto de permisos de catálogo y costos en los roles plantilla
**Estado:** Tomada · **Ámbito:** `RoleTemplates`

D71 previó exactamente esto: los seis roles se definen desde la Iteración 1 y **el reparto operativo se
afina en la iteración que construye cada módulo**. Mesero y cajero ganan `catalog.articles.view` y
`catalog.prices.view` —dicen los precios en voz alta— y **no** ven costos: el costo es información
sensible del negocio. El almacenista gana `costing.costs.view/update/history.view` y
`costing.recipes.view`, porque es quien recibe la mercancía y tiene la factura del proveedor en la
mano; negarle la captura obligaría a que un gerente teclee costos que no vio. Queda **fuera** de su
alcance el precio sugerido y el margen: ve lo que cuesta, no lo que se gana.

**Cero permisos nuevos:** el catálogo cerrado de D72 ya los tenía todos.

### D99 — Lectura de datos de referencia del catálogo con `catalog.articles.view`
**Estado:** Tomada · **Ámbito:** rutas de `Catalog`

Unidades, categorías y etiquetas se **leen** con `catalog.articles.view` y se **escriben** con su
permiso propio (`catalog.units.manage`, `.categories.manage`, `.tags.manage`). Son datos de referencia:
cualquiera que capture una receta o consulte un artículo los necesita. Inventar `catalog.units.view` y
compañía sería agregar tres permisos que nadie pidió y que cada tenant tendría que marcar en cada rol
para que el sistema funcionara — contra el catálogo cerrado de D10.

### D100 — `recipes` nace sin `modifier_id`: deuda declarada hasta el paso 10
**Estado:** Tomada · **Ámbito:** `Costing`

El diseño (§3.1) define el dueño de una receta como artículo **XOR** modificador, con dos FK nullable y
un `CHECK` de exclusividad. Pero `modifiers` es el paso 10 de la iteración y **no existe**, así que su
FK no se puede declarar.

Se construye con `article_id NOT NULL` y en el paso 10 se hace nullable, se agrega `modifier_id` y el
`CHECK`. El índice único `(tenant_id, article_id)` sigue sirviendo cuando la columna admita NULL: MySQL
no deduplica NULL, que es exactamente lo que hará falta para que las recetas de modificador no
colisionen entre sí.

**Alternativa descartada:** crear las tablas de modificadores ahora, sin código que las use. Se
descartó por la misma razón por la que se difirieron las ventanas de horario — una tabla sin consumidor
aparenta una capacidad que no existe — y porque el cambio pendiente es una migración pequeña y sabida.

### D101 — La detección de ciclos evalúa el estado POSTERIOR a la escritura
**Estado:** Tomada · **Ámbito:** `Costing`

Guardar una receta **reemplaza** sus líneas. Validar línea por línea contra el grafo actual respondería
sobre un grafo que ya no va a existir: rechazaría combinaciones legítimas —quitar "pan usa masa" y a la
vez poner "masa usa pan" es válido y una validación ingenua lo llamaría ciclo— y aceptaría ciclos que
sólo aparecen con el conjunto completo de líneas.

El razonamiento que hace suficiente una sola comprobación: guardar una receta cambia únicamente las
aristas **salientes del artículo dueño**, así que cualquier ciclo nuevo tiene que pasar por él. Basta
preguntar si el dueño se alcanza a sí mismo en el grafo resultante.

Se valida **antes** de escribir y dentro de la transacción, no con un job posterior: un ciclo guardado
hace que el recálculo de costos no termine nunca, y descubrirlo en producción significa una cola
atascada más datos que el usuario cree correctos.

El mensaje lleva el **camino completo** («Masa → Torta → Pan → Masa»). "Se detectó un ciclo" obligaría a
buscarlo a mano entre decenas de recetas.

### D102 — El grafo de recetas es una estructura de datos separada de la persistencia
**Estado:** Tomada · **Ámbito:** `Costing\Domain\RecipeGraph`

Dominio puro, sin base de datos: se construye desde fuera y se pregunta. Es lo que permite probar el
algoritmo con grafos armados a mano, **incluidos los que el sistema jamás permitiría guardar**. Sin esa
separación no habría forma de saber si el detector encuentra ciclos o si simplemente nunca encuentra
nada, y la suite de grafos corre en un segundo en lugar de treinta.

Recorrido en anchura y no en profundidad, para devolver el camino más corto — el más fácil de entender
para quien tiene que arreglar la receta. `dependentsOf()` recorre las aristas al revés en lugar de
mantener un segundo grafo que podría desincronizarse; la usará el recálculo transitivo del paso 7.

Prueba explícita del **grafo en diamante**: el pan y la salsa usan los dos la misma sal. Se llega a la
sal por dos caminos y no hay ciclo. Es completamente normal en cocina y es lo que un detector con el
conjunto de visitados mal usado rechazaría.

### D103 — `PUT` de la receta responde 200 incluso cuando la crea
**Estado:** Tomada · **Ámbito:** `Costing\Http`

La receta es un recurso **único por artículo** (invariante I1), su URL existe siempre y `PUT` es un
reemplazo idempotente, así que no hay recurso nuevo que anunciar.

El código se fija explícitamente porque Laravel devuelve 201 por su cuenta cuando el modelo que envuelve
el Resource acaba de crearse (`wasRecentlyCreated`). Es una comodidad razonable para un `POST` de
colección y aquí sería una incoherencia: el mismo `PUT` respondería 201 la primera vez y 200 las
siguientes, y el cliente tendría que tratar dos códigos para una sola operación. Lo detectó la prueba.

### D104 — Un artículo sin receta devuelve 404, no una receta vacía
**Estado:** Tomada · **Ámbito:** `Costing\Http`

"No tiene receta" y "tiene una receta sin ingredientes" son estados distintos, y el segundo **no
existe**: una receta sin ingredientes se rechaza, porque su costo sería cero y el sistema sugeriría
venderlo gratis. Devolver una receta vacía haría indistinguibles los dos casos para el cliente.

Eliminar la receta **no** le quita al artículo la capacidad de producible: eso es una decisión de
catálogo con su propio permiso. Lo que desaparece es su composición.

### D105 — Los costos calculados en cascada van a `article_costs` con `origin = recipe_cascade` (P5)
**Estado:** Tomada **aplicando la recomendación de P5**, que estaba formalmente abierta · **Ámbito:** `Costing`

D14 define el costo vigente como "el último costo **de adquisición**", y un platillo no se adquiere: su
costo se calcula. Aun así los dos orígenes viven en la misma tabla, porque el usuario quiere UNA pantalla
de "cómo evolucionó el costo de mis enchiladas" y partirla en dos historiales obligaría a unirlos en cada
lectura para siempre.

La condición que la decisión obliga a respetar, y se respeta con prueba: **el promedio del periodo de D14
se calcula sólo sobre orígenes de adquisición**. Mezclar un costo calculado con costos de compra da un
número sin significado.

**Costo de revertirla:** crear la tabla aparte, mover las filas con `origin = recipe_cascade` y unir las
dos en la pantalla de historial. Es una migración de datos acotada, no un rediseño.

### D106 — Un componente sin costo hace el resultado NO CALCULABLE, no cero
**Estado:** Tomada · **Ámbito:** motor de costeo

Si a cualquier profundidad falta un costo capturado, el costo del artículo es `null` y se devuelve la lista
de lo que falta. Sumar los componentes conocidos daría un número **más bajo que el real presentado como
completo**, y de ahí saldrían un precio sugerido y un margen equivocados. Un número plausible y falso es
peor que la ausencia de número.

Se reportan las **hojas** que faltan, no los intermedios: decir "falta el costo de Masa" es cierto y no es
accionable cuando lo que hay que capturar es el costo de la levadura, tres niveles abajo.

`RecostArticle` tampoco escribe nada en ese caso, y **no borra la proyección anterior**: el último costo
conocido sigue siendo la mejor información disponible, y el desglose es lo que explica qué falta.

### D107 — El motor recalcula las sub-recetas en lugar de leer su proyección
**Estado:** Tomada · **Ámbito:** motor de costeo

La proyección de una sub-receta puede estar desactualizada, y heredar ese valor propagaría el desfase hacia
arriba sin dejar rastro. Recalcular es determinista: el mismo catálogo da el mismo número siempre. La
proyección existe para quien sólo necesita "el costo" —inventarios al valuar, el POS—, no para alimentar
este cálculo.

Se paga con memoización del **desglose completo** por artículo: en un grafo en diamante —el pan y la
empanada usan los dos la misma masa— la masa se costea una vez por cálculo.

Guardia de ciclos en la pila de recursión, aunque guardar un ciclo sea imposible: si las filas llegaron por
otro camino —SQL a mano, una importación— la alternativa es un proceso que no termina. Se responde **409**
y no 422: no hay nada en la petición que el usuario pueda corregir.

### D108 — `Decimal::divide`: dividir con dígitos de guarda, nunca `bcdiv` a secas
**Estado:** Tomada · **Ámbito:** `Shared`, y obligatorio en toda división monetaria

`bcdiv($a, $b, 8)` **trunca** al octavo decimal, y redondear después a esa misma escala no corrige nada: el
dígito que habría decidido el redondeo ya se perdió. Hay que dividir con más escala de la que se quiere y
redondear al final.

Lo destapó la prueba de costeo de tres niveles: 10 ÷ 600 daba `0.01666666` en lugar de `0.01666667`, y ese
truncamiento se propagaba hacia arriba hasta mover el cuarto decimal del costo del platillo. El sesgo es
**siempre hacia abajo**, así que el margen que el sistema reporta sale optimista sin que nada falle.

Al centralizarlo apareció el mismo defecto en un segundo lugar que ya estaba escrito y con la suite en
verde: `UnitConverter::convert()` dividía con `bcdiv` a secas, así que sesgaba hacia abajo **toda cantidad
convertida del sistema**. Corregido y con prueba propia (una unidad de factor 3: convertir 2 daba
`0.66666666`).

`Decimal` tiene ahora suite propia, incluida la prueba de que `bcdiv` + `round` a la misma escala **no**
equivale a `Decimal::divide`.

### D109 — Un job lleva su `tenant_id` explícito y abre el contexto él mismo
**Estado:** Tomada · **Ámbito:** todo job del proyecto

Primer job del proyecto, así que fija el patrón. En el worker **no hay contexto de tenant**, y el global
scope de ADR-002 lanza excepción sin él.

ADR-002 dice que el `tenant_id` jamás llega como parámetro del cliente. Un job **no es un cliente**: es la
continuación de una petición que ya lo resolvió. Así que lo lleva en sus propiedades y abre el contexto con
`TenantContext::runFor()`, que además lo restaura en `finally` — el worker queda como estaba para el
siguiente job, que puede ser de otro negocio.

**Consecuencia obligatoria: los jobs llevan IDENTIFICADORES, no modelos.** `SerializesModels` vuelve a
consultar el modelo al deserializar, y esa consulta ocurre **antes** de que el job pueda abrir el contexto:
el scope global lanzaría `MissingTenantContextException` y el job fallaría siempre, en producción y nunca en
pruebas. Es una trampa con forma de comodidad.

La suite corre con `QUEUE_CONNECTION=sync`, así que el ciclo real —serializar, deserializar sin contexto,
ejecutar— no se ejercitaría solo. Hay una prueba que lo hace explícito: serializa el job, lo revive, borra el
contexto y lo ejecuta.

### D110 — El recosteo en cascada no depende del orden de los dependientes
**Estado:** Tomada · **Ámbito:** `RecalculateDependentCosts`

Parecería que hay que recostear de abajo hacia arriba —la masa antes que el pan, el pan antes que la torta—
pero no hace falta: el motor **recalcula las sub-recetas** en lugar de leer su proyección (D107), así que
cada recosteo baja hasta las hojas por su cuenta. El orden sólo cambia en qué instante se escribe cada fila
de historial, no el número.

Un job cubre el subárbol completo, sin re-despachos en cadena, porque `dependentsOf()` ya es transitivo.

**Límite conocido, dicho en voz alta:** cambiar el costo de la sal recostea todo lo que la usa, y cada
recosteo recalcula su propio árbol. En un catálogo grande eso es N × profundidad recorridos. Es aceptable
para un catálogo de restaurante —cientos de artículos, no cientos de miles— y la salida, si algún día
molesta, es compartir la memoización entre los recosteos de un mismo job. No se hizo ahora porque sería
optimizar sin evidencia.

Un ciclo encontrado al costear no tira el job: se registra y se sigue con los demás dependientes. Un
artículo con recetas corruptas no debe impedir que los otros treinta queden costeados.

### D111 — Guardar una receta recostea al dueño en el momento; los dependientes van por cola
**Estado:** Tomada · **Ámbito:** `RecalculateOnRecipeChanged`

Quien acaba de guardar una receta está mirando la pantalla y espera ver el costo nuevo. Dejarlo a la cola le
mostraría el costo viejo durante unos segundos, y la conclusión natural sería que el sistema no guardó su
cambio. Los dependientes pueden ser decenas y a nadie le urge verlos en el mismo instante.

**Eliminar la receta NO recostea al dueño.** Su proyección conserva el último costo conocido, que es
exactamente lo que P4/D95 define —la proyección espeja la última fila del historial inmutable— y borrar una
receta no borra historia. Quien pregunte por el desglose recibirá "no calculable", que es la respuesta
honesta. Los dependientes sí se recalculan: para ellos un componente dejó de tener costo calculable, y eso
cambia su propia calculabilidad.

`RecostArticle` gana un guard: **sin receta activa no escribe nada**. Sin él, un artículo al que se le borró
la receta podía recibir una fila con `origin = recipe_cascade` que en realidad contenía su costo capturado —
una mentira en la columna que D105 usa para separar los dos mundos.

### D112 — Una captura retroactiva no dispara la cascada
**Estado:** Tomada · **Ámbito:** `RecalculateOnCostChanged`

El evento `ArticleCostChanged` lleva `becameCurrent`, y el listener sólo actúa cuando es verdadero.
Recostear por una captura que no quedó como vigente sería trabajo inútil **con resultado equivocado**: el
motor usaría el costo actual —no el retroactivo— y escribiría en cada dependiente un recálculo idéntico al
que ya existe, ensuciando su historial sin cambiar un solo número.

### D113 — Markup override sólo por artículo; categoría diferida (P6)
**Estado:** Tomada **aplicando la recomendación de P6**, que estaba abierta · **Ámbito:** `Costing`

Dos niveles: `articles.markup_percent`, y si es NULL, el ajuste `pricing.default_markup_percent` del tenant.

Un negocio querrá markup por categoría —250 % en bebidas, 180 % en alimentos— y eso queda **diferido con
deuda declarada**. La razón para no hacerlo ahora: el precio es *sugerido* y el humano decide (D15), así que
un default ausente cuesta una edición más por artículo, no un número equivocado. Estrenar una cascada de
cuatro niveles —artículo, subcategoría, categoría, tenant— en la primera iteración que la usaría es más
riesgo que valor.

**Costo de revertirla:** una columna `markup_percent` en `article_categories` y dos peldaños más en
`SuggestPrice::markupFor()`. Aditivo, sin migración de datos.

### D114 — Ajuste nuevo `pricing.stale_price_tolerance_percent` (P13)
**Estado:** Tomada **aplicando la recomendación de P13** · **Ámbito:** Configuration, ámbito tenant, default 5 %

Caso de uso, como exige D20: el semáforo de "precio desactualizado" de D15 necesita un umbral. Sin él, el
redondeo que el propio tenant configuró marcaría en rojo el 100 % del catálogo el primer día — y un semáforo
que siempre está en rojo no lo mira nadie, con lo que se pierde justo la señal que D15 quería dar.

El semáforo compara el **valor absoluto** de la desviación: un precio muy por encima del sugerido está tan
desactualizado como uno por debajo. El que está por debajo cuesta dinero; el que está por encima ahuyenta
clientes.

Un artículo **sin precio** no se marca: está sin precio, que es otra cosa y se ve en otro lado. Marcarlo
llenaría el semáforo de artículos que nadie intentó cobrar todavía.

### D115 — El cambio de precio lo sirve `Costing`, pero lo escribe `Catalog`
**Estado:** Tomada · **Ámbito:** `Catalog`, `Costing`

Historizar un cambio de precio exige el **snapshot de costeo** —costo, markup y sugerido del momento— y
`Catalog` no puede depender de `Costing` (P1): el candado lo rechazaría y declararlo crearía un ciclo el
mismo día.

La resolución reparte por capas en lugar de romper la regla:

- `Catalog\Application\ChangeArticlePrice` escribe `articles.base_price` y `price_changes` en una transacción,
  y **recibe el snapshot como dato** (tres cadenas nullable). El precio sigue siendo dato maestro del
  catálogo y su dueño sigue siendo ese módulo.
- El endpoint `PUT /articles/{ulid}/price` vive en `Costing`, que sí puede depender de `Catalog`. No es
  fontanería: D63 define `Costing` como "recetas y costeo, **incluido el precio sugerido**", y decidir un
  precio mirando el margen es una acción de costeo.
- El permiso sigue siendo `catalog.prices.update`: quien cambia precios administra el catálogo comercial, no
  quien captura costos.

El **historial** (`GET /price-changes`) se queda en `Catalog`: no necesita nada de `Costing` porque el
snapshot ya está guardado en cada fila.

### D116 — `SuggestPrice` no escribe nada
**Estado:** Tomada · **Ámbito:** `Costing`

Es sólo lectura, y ésa es la mitad de D15 hecha estructura: "el sistema sugiere, el humano decide". Un
servicio que sugiriera escribiendo haría **imposible** garantizar que el sistema no sobrescribe una decisión
humana. La sugerencia se calcula al leer y no se almacena en ninguna parte.

Sin costo calculable **no hay sugerencia**, y eso es distinto de sugerir cero: un sugerido de cero invitaría a
regalar el platillo. Se devuelve `null` más la lista de insumos sin costo, que es lo accionable.

### D117 — El redondeo del sugerido sube al siguiente múltiplo, no al más cercano
**Estado:** Tomada · **Ámbito:** `RoundingMode`

$47 con múltiplos de 5 sugiere **$50**, no $45. Un precio sugerido es un piso de rentabilidad, y bajarlo para
llegar al múltiplo más cercano recortaría el markup que el negocio configuró — silenciosamente y en cada
artículo.

Con dos salvaguardas que tienen prueba: un monto que ya es múltiplo exacto **no** sube al siguiente (si no,
$50 sugeriría $55 en cada consulta), y el cero se queda en cero (un insumo regalado por el proveedor no debe
convertirse en un sugerido de $5).

El sugerido **sin redondear** viaja junto al redondeado: los dos explican por qué el precio propuesto no es
exactamente costo × (1 + markup).

### D118 — El margen se calcula al leer; nunca se almacena
**Estado:** Tomada · **Ámbito:** `Catalog`, `Costing`

`price_changes` guarda el **markup** (utilidad ÷ costo) y el costo del momento. El **margen** (utilidad ÷
precio) se deriva de esos dos y de un dato que ya está en la fila. Guardar los dos invitaría a que se
contradijeran, y son la pareja que D13 prohíbe confundir.

Hay prueba ejecutable del glosario: costo 100 y markup 200 % dan sugerido 300 y margen **66.67 %**.
Confundirlos hace que un negocio crea que gana el triple de lo que gana.

Lo que **sí** se guarda aunque sea derivable son el costo, el markup y el sugerido del momento — y la
diferencia con el margen es la que importa: el margen se recalcula igual mañana a partir de la misma fila,
mientras que el costo y el markup de hace ocho meses ya cambiaron y no se pueden reconstruir.

### D119 — Override por sucursal: una tabla, dos dimensiones, `NULL` = heredar
**Estado:** Tomada · **Ámbito:** `Catalog`

`article_branch_overrides` lleva precio y disponibilidad en la misma fila, con `NULL` significando **heredar**
el dato maestro — igual que la cascada de configuración del kernel. La ventaja concreta frente a dos tablas es
que `branch_id` es NOT NULL: el índice único funciona sin trucos y **no reaparece** el problema de `NULL` en
índice único que resolvió D78 y que en categorías obligó a una columna generada (D93).

Cascada de **dos niveles y nada más**: override de sucursal → dato maestro del artículo.

**Una fila que hereda todo se borra.** Quitar el precio de una sucursal que no tenía override de
disponibilidad dejaría las dos columnas en NULL, y eso es indistinguible de no tener override: conservarla
daría dos respuestas posibles a "¿esta sucursal tiene precio propio?" para el mismo estado.

**`is_available_in_pos` es nullable y su cast lo respeta.** `false` dice "no está disponible aquí"; `null` dice
"usa lo del negocio". Castear NULL a false haría desaparecer platillos de una sucursal sin que nadie lo
pidiera, y volver a heredar sería imposible.

**El canal sigue diferido a la Iteración 9**, con la deuda ya declarada en el diseño: en v1 sólo existe un
canal transaccional, así que la dimensión llegará como columna aditiva cuando haya un segundo canal contra el
que probarla.

### D120 — Un override igual al maestro SIGUE siendo un override
**Estado:** Tomada · **Ámbito:** `EffectivePricing`

`priceIsOverridden` viaja junto al valor. No es cosmético: el día que el negocio suba su precio a $90, la
sucursal que decidió $85 se queda en $85 y la que heredaba pasa a $90. Distinguirlo es lo único que permite
explicarlo en pantalla, y es la misma distinción que la configuración jerárquica hace entre "hereda" y
"configurado aquí".

`EffectivePricing` es dominio puro y recibe **valores**, no modelos: así los cuatro casos de la cascada se
prueban sin tocar la base, y el llamador decide cómo obtuvo el override — una consulta, una relación
precargada, un mapa en memoria—, que es justo la parte que cambia entre el detalle de un artículo y el
catálogo completo del POS.

### D121 — Precio y disponibilidad por sucursal son endpoints distintos
**Estado:** Tomada · **Ámbito:** `Catalog`, `Costing`

Dos acciones de naturaleza distinta y con permisos distintos: el precio exige `catalog.prices.update`, se
historiza en `price_changes` con `branch_id` y lleva el snapshot de costeo; la disponibilidad exige
`catalog.articles.manage`, **no** se historiza como precio y sólo queda en la bitácora técnica.

Unirlas en un endpoint obligaría a que un permiso cubriera al otro — y el que quedaría cubierto es el de
precios, que es zona de auditoría (§6.7).

Por eso el precio por sucursal lo sirve `Costing` (D115) y la disponibilidad `Catalog`. La regla de **alcance
de sucursal** se comparte entre los dos controladores como método estático en lugar de duplicarse: dos copias
de una regla de autorización son dos sitios donde una se queda sin actualizar.

### D122 — El alcance de sucursal se verifica al escribir un override
**Estado:** Tomada · **Ámbito:** `Catalog`, `Costing`

El `tenant_id` protege del negocio ajeno; **no** de la sucursal ajena dentro del propio negocio. Un gerente con
alcance sobre una sola sucursal podía cambiar el precio y la disponibilidad de otra, porque el binding de ruta
resuelve cualquier sucursal del tenant.

Es exactamente el hueco que `membership_branch_scopes` existe para cerrar, y hay que cerrarlo en el
controlador: se verifica `canOperateInBranch()` y se responde 403. Con prueba de las dos direcciones — en su
sucursal sí, en la ajena no, y la fila no se escribe.

### D123 — El listado acepta `?branch` y devuelve valores efectivos precargados
**Estado:** Tomada · **Ámbito:** `Catalog\Http`

Es la consulta que hará el POS al pintar su pantalla. Los overrides de esa sucursal se **precargan** para todo
el listado: sin la precarga, resolver el precio efectivo de 400 artículos serían 400 consultas.

La sucursal se resuelve **una vez** en el controlador y su llave interna viaja en los atributos de la petición,
de donde la lee el Resource. La alternativa —que el Resource resolviera el ULID— reintroduciría la consulta por
fila que la precarga evita.

**Sin `?branch` el recurso describe el dato maestro** y no inventa un "efectivo": es lo que edita la
administración del catálogo, y devolver un efectivo sin sucursal obligaría a elegir una arbitrariamente.

Una sucursal inexistente o de otro negocio **no es un error**: no se resuelve y el listado devuelve los datos
maestros. No se confirma la existencia de un recurso ajeno, y el cliente ve el catálogo de su negocio en lugar
de un error sobre una sucursal que para él no existe.

### D124 — Deuda D100 pagada: el dueño de una receta es artículo XOR modificador
**Estado:** Tomada · **Ámbito:** `Costing`

La migración del paso 10 hace `recipes.article_id` nullable, agrega `modifier_id` con su FK y añade el `CHECK`
de exclusividad que D100 dejó pendiente. El plan era exactamente el escrito entonces, y el índice único
`(tenant_id, article_id)` siguió sirviendo sin tocarlo porque MySQL no deduplica NULL: las recetas de
modificador no colisionan entre sí.

Dos FK nullable y no una relación polimórfica, por integridad referencial: con `owner_type`/`owner_id` nada
impediría una receta huérfana apuntando a un id borrado, y el día que apareciera esa fila el costeo devolvería
un número sin explicación. Con prueba de que la base rechaza los dos dueños a la vez **y** ninguno.

### D125 — Las reglas de un grupo de modificadores no se sobrescriben por artículo (P8)
**Estado:** Tomada **aplicando la recomendación de P8** · **Ámbito:** `Catalog`

Un artículo que necesita reglas distintas usa un grupo distinto. Permitir override metería una cascada en la
validación más caliente del POS —"¿puedo comandar esto?"— y ahí una regla ambigua no es un bug de interfaz: es
un platillo mal preparado y un cliente esperando.

Los grupos son del tenant y **se reutilizan**: "Término de la carne" lo comparten ocho cortes. Lo que sí vive
en el pivote es el **orden de presentación**, porque el mismo grupo puede ir primero en un artículo y tercero en
otro. El detalle del grupo informa a cuántos artículos afecta un cambio — es lo que hace responsable editar algo
compartido.

**Dos estados imposibles, cerrados en la base y no sólo en validación:** un máximo menor que el mínimo (ninguna
selección sería válida) y un grupo obligatorio con mínimo cero (no obligaría a nada). Al **editar** las reglas
se evalúan sobre el estado final, porque sólo llega lo que cambia y subir el mínimo puede invalidar una
combinación que era válida.

Y una tercera protección en el servicio: **no se puede dejar un grupo obligatorio sin ninguna opción activa**.
Sería exigir elegir de una lista vacía, y es la clase de estado que se descubre en hora pico.

### D126 — La receta de un modificador rinde una aplicación, siempre
**Estado:** Tomada · **Ámbito:** `Costing`

`output_quantity` se fuerza a 1 y la unidad a una de dimensión `count`: un modificador no se mide en gramos, se
aplica o no se aplica. Si el grupo admite cantidad —los "3 shots" de D7— es el **POS** quien multiplica; la
receta sigue siendo por unidad.

No se inventa una unidad "aplicación": sería una unidad más en el selector de cada receta para expresar algo que
el usuario nunca elige. Se usa la pieza, que el alta del negocio ya siembra (D97).

**No se detectan ciclos** en las recetas de modificador, y no hace falta: nada consume un modificador como
ingrediente, así que no puede formar parte de un ciclo. Ya estaba anticipado en `RecipeGraph`, que sólo carga
recetas con `article_id`.

### D127 — Un modificador sin receta cuesta CERO, no «desconocido»
**Estado:** Tomada · **Ámbito:** `CalculateArticleCost::modifierBreakdown()`

«Término medio» no gasta insumos: su costo es cero y es un dato completo. Es la diferencia con un artículo sin
costo capturado, donde el costo es **incalculable** (D106) — y confundirlas haría incalculable el platillo entero
por llevar un modificador que no consume nada.

El costeo de modificadores **reutiliza la fórmula de las líneas**, extraída a un método compartido. Duplicarla
habría sido la forma de que las dos copias divergieran, y una de ellas invirtiendo el rendimiento de D21 pasaría
inadvertida — el error apunta siempre en la dirección optimista.

### D128 — Matriz de autorización exhaustiva por módulo, con candado de cobertura
**Estado:** Tomada · **Ámbito:** pruebas de `Catalog` y `Costing`

La matriz de la Iteración 1 verificaba una **muestra** de permisos: los de máxima auditoría. La de la Iteración 2
cubre los **dieciséis** de estos dos módulos, y tiene un candado que lo demuestra: compara la matriz contra
`PermissionCatalog::forModules(['Catalog','Costing'])` en las dos direcciones. Si D72 agrega un permiso y nadie lo
reparte aquí, falla; y un typo en la matriz también, porque estaría probando que nadie tiene un permiso
inexistente.

Se verifica a través del **servicio de autorización** y no leyendo las plantillas: lo que importa no es lo que la
plantilla dice, es lo que el sistema responde.

La lógica del reparto en una frase: **el costo es información del negocio y el precio no**. Un mesero dice los
precios en voz alta y no necesita el margen; un almacenista tiene la factura del proveedor en la mano y captura
costos, pero no ve lo que se gana.

### D129 — Toda ruta de `/api/v1` exige un permiso, con tres excepciones declaradas
**Estado:** Tomada · **Ámbito:** candado estructural

Dos fallos que **no se ven**, cerrados:

1. **Una ruta sin permiso es un endpoint abierto.** Basta olvidar el middleware para que cualquier usuario
   autenticado —un mesero— pueda usarla. No falla nada y no hay error en ningún log: la ruta funciona para todos.
   Con más de setenta rutas y nueve iteraciones por delante, revisarlo a mano no es una estrategia.
2. **Un permiso mal escrito es un 403 permanente.** `can:catalog.artcles.view` no falla al arrancar: falla al
   usarse, con un "no tienes permiso" que parece un problema de configuración de roles. El tenant marcaría y
   desmarcaría casillas sin que nada cambie.

Las tres excepciones, cada una con una razón que no admite permiso: `api/v1` (descubrimiento, sin dato de
negocio), `api/v1/context` (exigirle permiso sería circular — es de donde el cliente saca la lista de permisos) y
`api/v1/authorizations` (el PIN de ADR-008 tiene su propio mecanismo, deliberadamente distinto del rol activo).
Agregar una cuarta es una decisión de arquitectura.

Verificado a mano que muerde: quitando un permiso de una ruta y metiendo un typo en otro, el candado falla y
**nombra las rutas exactas**. Y tiene meta-verificación: si el detector dejara de leer el middleware, o si una
excepción quedara apuntando a una ruta que ya no existe —una puerta abierta esperando— la prueba falla.

### D130 — La lista de tablas inmutables de §7 se verifica en las dos direcciones
**Estado:** Tomada · **Ámbito:** candado estructural, ARQUITECTURA_MAESTRA §7

Cada tabla append-only tenía su prueba de inmutabilidad, pero **nadie verificaba la lista completa**: un modelo
nuevo sobre una tabla que debería ser inmutable podía nacer sin el trait, y su prueba simplemente no existiría —
no hay nada que falle cuando una prueba no se escribe.

El candado invierte la carga: la lista vive en la prueba y el modelo tiene que cumplirla. Y comprueba la
dirección inversa, que es la que nadie piensa: un modelo con el trait que no esté en §7 es una tabla que debería
figurar en la especificación, o un trait puesto por error que va a impedir correcciones legítimas.

Verifica además que el trait cierre **la tercera vía** —el query builder—, que es la más ancha y la más fácil de
olvidar: `Model::query()->update()` no dispara eventos, así que un trait que sólo escuchara `updating` la dejaría
abierta.

§7 se actualizó: la lista suma el historial de estados del tenant (D75) y apunta al candado, con la nota de
agregar el diario financiero y el kardex cuando se construyan.

### D131 — La UI de catálogo cuelga del artículo: la receta y el costo no tienen pantalla propia
**Estado:** Tomada · **Ámbito:** `resources/js/Pages/Admin/Catalog`

Seis pantallas: artículos (listado y ficha), categorías, unidades, etiquetas y grupos de modificadores. La receta,
el costo, el precio con su historial, los precios por sucursal, las presentaciones de compra y los modificadores
asignados son **paneles de la ficha del artículo**, no pantallas.

No es una decisión de maquetación. Una receta no existe sin su artículo (invariante I1) y un costo se lee siempre
preguntando «¿cuánto cuesta ESTO?». Darles URL propia habría creado pantallas huérfanas y un listado de recetas
que nadie consultaría.

Las pestañas dependen de las **capacidades** del artículo y de los permisos del rol activo: un insumo no tiene
pestaña de precio porque no se vende, y quien no ve costos no ve la pestaña en lugar de encontrarse un 403 al
abrirla. Es la consecuencia visible de D17 — mostrarlas todas siempre enseñaría que a un jitomate «le falta»
precio de venta.

Excepción declarada: la pestaña de receta aparece también cuando el artículo **ya tiene** receta aunque le hayan
quitado la capacidad de producible. Si no, una receta seguiría costeando sin que nadie pudiera verla, y un costo
calculado por algo invisible es la clase de dato que nadie logra explicar meses después.

### D132 — El listado de artículos no trae costos, y la ficha los pide aparte
**Estado:** Tomada · **Ámbito:** `Admin/Catalog/Articles/Index.vue`

Consecuencia directa de P1: `ArticleResource` no expone costo ni precio sugerido porque son de `Costing`, y
traerlos al listado serían N+1 llamadas para pintar una tabla. El costo se ve en la ficha, una vez y con su
desglose.

El precio sí está en el listado: es dato maestro del catálogo. Y el filtro por sucursal cambia lo que significa esa
columna —del precio del negocio al precio efectivo allí—, marcando con una insignia lo que la sucursal decidió:
«hereda $85» y «Polanco decidió $85» se ven igual hasta el día que cambie el precio maestro.

### D133 — Comando de negocio de demostración, no seeder
**Estado:** Tomada · **Ámbito:** `comandia:demo:seed`

§11 pedía un tenant de demostración para QA y demos comerciales. Se implementa como comando explícito y no como
parte de `DatabaseSeeder`, que lo ejecutaría cualquier despliegue; está bloqueado en producción salvo `--force`.

La segunda razón es la que lo hizo urgente: **verificar la interfaz en un navegador exige datos**. Una pantalla de
catálogo vacía se ve idéntica a una pantalla de catálogo rota. Los datos son de una fonda con precios y costos
verosímiles porque un catálogo de «Producto 1 / Producto 2» no revela nada — los defectos de formato, de redondeo y
de cascada aparecen cuando los números tienen la forma de los de verdad.

El borrado con `--fresh` usa `DELETE` directo y no los modelos, porque las tablas inmutables rechazan el borrado
por diseño. Es el único lugar del sistema autorizado a saltárselo, y sólo porque borra un tenant ficticio completo.
Dos FK a la propia tabla exigen estrategias distintas y ninguna se adivina: en `article_costs` se anula
`source_cost_id` —la cadena causal tiene cualquier profundidad—, y en `article_categories` no se puede anular
`parent_id` porque un CHECK amarra `level` con él, así que se borran las hijas primero; basta una pasada porque D18
limita el árbol a dos niveles.

### D134 — Los importes de costeo se redondean al PRESENTAR, no al calcular
**Estado:** Tomada · **Ámbito:** `CostBreakdownController`, `ArticlePriceController`, `ModifierRecipeController`, `ArticleCostController`

El motor de costeo calcula con muchos decimales a propósito: redondear en cada paso de una cascada acumula error, y
el costo de un platillo terminaría dependiendo de cuántos niveles tiene su receta. Pero lo que se calcula como
`0.04621064` **se guarda como `0.0462`**, porque la columna es `DECIMAL(12,4)`.

Sin redondear al presentar, la misma cantidad aparecía con dos valores distintos en la misma pantalla —«$48.2644»
como costo vigente y «$48.26440723» en el desglose—, que es justo lo que hace desconfiar de un desglose que existe
para dar confianza. Se redondea en el servidor con `bcmath` y media-arriba: el frontend no hace aritmética de
dinero (§7).

Alcanzó también al promedio del periodo, que usaba `round()` sobre un `float` —prohibido por §7— y devolvía tres
decimales junto a un costo de cuatro.

Lo encontró el navegador. Las pruebas comparaban el valor contra el que produce el mismo motor, así que coincidían
siempre.

### D135 — Buscar con acentos no puede reventar un listado
**Estado:** Tomada · **Ámbito:** `ListQuery::applySearch()`

MySQL 8 se niega a comparar una columna `ascii_bin` con un parámetro que no es ASCII (error 3988). Los códigos son
`ascii_bin` a propósito (D58), para que `Kg` y `kg` sean valores distintos. El resultado: en un SaaS mexicano,
buscar «azúcar», «jalapeño» o «piña» devolvía **500** en los siete listados con columna de código —los cinco del
kernel incluidos—, con 544 pruebas en verde.

`applySearch` descarta las columnas ASCII cuando el término no es ASCII. **No pierde resultados**: una columna
ASCII no puede contener «azúcar», así que la comparación que se omite nunca habría coincidido. Lo que se omite es un
error. Si todas las columnas buscables resultan ASCII, se fuerza el conjunto vacío en lugar de no filtrar: devolver
la lista completa a quien buscó algo parece correcto y es lo peor.

La colación se lee del **esquema** y no de una lista declarada en el código: una lista habría que actualizarla en
cada migración, y el día que alguien la olvidara volvería el 500. El esquema no puede desincronizarse de sí mismo.

El candado (`AccentedSearchTest`) barre **todos** los listados de colección de `/api/v1`, presentes y futuros,
porque el defecto no era del catálogo sino de `ListQuery`. Verificado que muerde: al revertir el arreglo, nombra los
siete endpoints.

Ninguna prueba lo veía porque **todas buscaban palabras sin acentos**. Es el punto ciego más incómodo de una suite:
no falta una prueba de una función, falta un dato en las que ya existen.

### D136 — Los refs de los composables se leen con `.value` en las plantillas, y hay candado
**Estado:** Tomada · **Ámbito:** `resources/js`, `tests/Architecture/FrontendRefUnwrapTest.php`

`useApiForm` devuelve refs. Vue los desenvuelve solo cuando son bindings de primer nivel del `setup`; como propiedad
de un objeto —`save.generalError`— **no**. Así que `v-if="save.generalError"` es siempre verdadero —el objeto Ref
existe aunque su valor sea `null`— y la interpolación imprime vacío: un **recuadro de error rojo, vacío y
permanente** en todas las pantallas.

Estaba en las nueve de la Iteración 1 y se repitió en las seis de la Iteración 2: treinta y cinco veces. Ninguna
prueba lo vio —no montan Vue— y no llama la atención, porque un error de verdad sí se muestra bien: lo único que
sobra es una caja vacía cuando no hay error.

El candado vigila las tres propiedades que fallan **en silencio** (`generalError`, `fieldErrors`, `isEmpty`).
`items` y `meta` quedan fuera: son nombres genéricos que daban falsos positivos, y además fallan ruidosamente —un
`v-for` sobre un Ref no itera y la tabla vacía se nota al primer vistazo. El candado existe para lo que no se nota.

### D137 — El buscador de artículos descarta sus resultados antes de buscar otra cosa
**Estado:** Tomada · **Ámbito:** `ArticlePicker.vue`

Tres defectos en el mismo sitio, encontrados al escribir «azúcar» en el buscador de ingredientes:

1. La excepción de la consulta se perdía en una promesa sin dueño, así que un fallo no se mostraba.
2. Los resultados anteriores se quedaban en pantalla: se buscaba «azúcar» y se seguía viendo «Jitomate». Es el peor
   resultado posible, porque parece una respuesta.
3. Dos respuestas en camino podían llegar al revés y la lenta de «jito» sobrescribía a la de «jitomate».

Se corrigen los tres: se limpian los resultados al empezar, se captura y se muestra el error, y sólo se pinta la
respuesta si sigue siendo la búsqueda vigente.

### D138 — La ficha del artículo carga todo en una tanda
**Estado:** Tomada · **Ámbito:** `Admin/Catalog/Articles/Show.vue`

Pedía el artículo y después los datos de referencia, pintando en cuanto llegaba el artículo. En el navegador las
pestañas aparecían de a una conforme llegaban los datos —«Sucursales» un segundo tarde, porque depende de cuántas
sucursales hay— y la barra se movía bajo el cursor: quien iba a pulsar «Costo» acababa en otra pestaña.

Ninguna de esas peticiones depende del resultado de otra: todas se resuelven con el ULID de la ruta. Encadenarlas no
daba nada y costaba un salto de ida y vuelta más.

### D139 — Las magnitudes del selector de unidades salen del servidor
**Estado:** Tomada · **Ámbito:** `Admin/Catalog/Units/Index.vue`

El selector decía «Piezas» mientras la tabla decía «Conteo», porque la etiqueta la traduce el enum del servidor
(D87) y el cliente tenía su propia copia escrita a mano. Dos nombres para la misma cosa en la misma pantalla:
exactamente el fallo que D87 existe para evitar, cometido otra vez donde nadie lo estaba vigilando.

Las magnitudes y las unidades base se derivan ahora de un catálogo de referencia cargado sin filtros. No es un
duplicado del listado: al filtrar por «dadas de baja», las bases —que están activas— desaparecían de la tabla y la
equivalencia se quedaba a medias, «1 kg = 1000». Y el factor se muestra sin los ceros que no dicen nada, recortando
la **cadena** y sin `parseFloat`: ese número multiplica todas las cantidades del sistema.

### D140 — El alcance por sucursal se puede cambiar: el permiso existía y la ruta no
**Estado:** Tomada · **Ámbito:** `PUT /api/v1/memberships/{ulid}/branches`

`identity.memberships.manage_branch_scopes` —«Definir en qué sucursales opera cada persona»— estaba en el catálogo
cerrado desde la Iteración 1 y **ninguna ruta lo usaba**. Un tenant podía marcar la casilla en un rol y no pasaba
nada: el alcance sólo se fijaba al dar de alta a la persona, y después no había forma de cambiarlo salvo entrando a
la base de datos.

Es el fallo inverso al que vigila D129: ése encuentra rutas que piden permisos inexistentes; éste era un permiso sin
ruta. **No se puede convertir en candado todavía**, y conviene decir por qué: el catálogo declara a propósito
permisos de iteraciones que no existen —punto de venta, inventarios—, así que exigir endpoint para cada permiso
haría fallar la suite por lo que aún no se ha construido. Queda como revisión al cerrar cada iteración: los permisos
de los módulos ya construidos sí deberían tener ruta.

Permiso propio y no el de editar datos, por lo mismo que los roles: corregirle el nombre a alguien y decidir dónde
puede cobrar son cosas de naturaleza distinta.

`has_all_branches` y la lista son **excluyentes**, y mandar las dos se rechaza en lugar de resolverse por
precedencia. «Todas» no es «las cinco que hay»: incluye las futuras. Una precedencia silenciosa sería el sistema
decidiendo por el usuario, y el resultado —una lista que parece la verdad mientras la bandera la ignora— no se
descubre hasta que alguien abre una sucursal nueva y no entiende quién entra.

### D141 — La ficha de personal: alta, roles, alcance y perfil laboral
**Estado:** Tomada · **Ámbito:** `Admin/Staff/Index.vue`, `Admin/Staff/Show.vue`, `components/identity/StaffForm.vue`

Cierra los tres huecos que quedaron abiertos al construir la UI del kernel.

El **alta** pregunta primero si la persona va a entrar al sistema, en lugar de tener dos formularios: lo que cambia
entre los dos casos es qué campos hacen falta, no lo que se está haciendo. Sin correo, el perfil laboral es
obligatorio porque **de ahí sale su nombre** (invariante I1, D66) — no es papeleo: una membresía sin ninguno de los
dos no tiene nombre que mostrar en ninguna pantalla.

El campo de código de empleado decía «se asigna solo» y era **falso**: el servidor no genera ninguno. La persona
quedaba sin código y sin poder autorizar con PIN, y nada lo avisaba. Ahora dice que es opcional y qué se pierde sin
él. No se agregó generación automática porque sería inventar una regla de negocio.

El **perfil laboral se pide al abrir su pestaña**, no al montar la ficha. No es una optimización: cuando el rol
activo puede ver la CURP, el RFC y el NSS, el servidor registra en la bitácora que se consultaron datos sensibles, y
pidiéndolo al montar, abrir la ficha de cualquier persona dejaba ese asiento aunque nadie hubiera mirado nada. Un
registro de accesos a datos personales que se llena de consultas que no ocurrieron es peor que inútil: diluye las
que sí.

### D142 — Un negocio inservible en la sesión no encierra a nadie
**Estado:** Tomada · **Ámbito:** `ResolveTenantContext`

Si el negocio guardado en la sesión desaparecía o quedaba suspendido, el middleware respondía **403 a todas las
rutas, incluidas `login` y `logout`**. La persona quedaba encerrada: no podía entrar a otro negocio ni cerrar
sesión, porque cerrar sesión también estaba prohibido. La única salida era borrar las cookies a mano, y la de sesión
es `HttpOnly`.

Las rutas de escape existían en la rama de «no hay negocio elegido» y **no** en la de «el negocio no sirve», que es
donde más falta hacen: en la primera al usuario no le ha pasado nada; en la segunda ya tiene un problema.

Ahora una navegación **olvida el negocio de la sesión** y va a elegir otro —porque una persona puede administrar
dos restaurantes (§4.1) y que uno esté suspendido no la deja fuera del otro—, mientras la API sigue devolviendo 403,
que es lo que un cliente necesita para saber qué pasó.

Lo encontró el navegador de la manera más tonta: re-sembrar el negocio de demostración con la sesión abierta. Es
exactamente lo que le ocurre a un cliente al que se le suspende la cuenta con la pestaña abierta.

### D143 — La pantalla de elegir negocio lee el perfil de empleado sin scope de tenant
**Estado:** Tomada · **Ámbito:** `TenantSelectionController`

Consecuencia del anterior, y un defecto por su cuenta: sin negocio elegido no hay contexto, y la carga previa del
perfil de empleado —modelo de dominio con scope— lo exigía. O sea que la pantalla que sirve para elegir negocio
respondía **500 justo cuando no había ninguno elegido**.

No se veía porque iniciar sesión con **una sola** membresía entra directo y nunca pasa por ahí. Sale a la luz con
dos negocios, o al quedar la sesión con uno que ya no sirve.

`membershipsAcrossTenants()` ya quitaba el scope a la consulta de membresías; había que quitarlo también a la carga
previa del perfil, que es otra consulta sobre otro modelo. Es la misma excepción de ADR-002 un paso más allá, y está
**declarada en el candado** con su razón escrita: lo único que lee entre negocios son los nombres de las membresías
del propio usuario autenticado.

### D144 — El perfil laboral por API estaba roto desde la Iteración 1
**Estado:** Tomada · **Ámbito:** `EmployeeProfileResource`

`GET` y `PUT /memberships/{ulid}/employee-profile` respondían **500 siempre**, con permiso y sin él: el recurso
desempaquetaba con `...` el resultado de `mergeWhen`, que devuelve un objeto —`MergeValue` o `MissingValue`— y no un
arreglo. El pipeline de recursos lo aplana al filtrar; el operador de propagación lo revienta antes.

La suite tenía una prueba del `DELETE`, que devuelve 204 y no pasa por el recurso, y **ninguna del `GET` ni del
`PUT`**. Es el hueco más silencioso que puede tener una suite: no es una aserción débil, es un par de endpoints sin
llamar nunca. Lo encontró el navegador al abrir la pestaña de perfil laboral de la primera persona.

Se conserva el contrato que el recurso documentaba: los datos fiscales viajan **al nivel superior** y la llave
**falta** cuando no hay permiso, en lugar de venir en `null`. La ausencia dice «no puedes verlo»; un `null` diría
«no hay dato», y mostrar «sin CURP» a quien simplemente no puede verla es mentirle. La UI se adaptó a ese contrato,
no al revés.

### D145 — El listado de personal muestra el alcance por sucursal
**Estado:** Tomada · **Ámbito:** `Admin/Staff/Index.vue`, `MembershipResource`

Una columna nueva, y con la distinción explícita: «Todas» lleva insignia porque **no** es lo mismo que enumerar las
que hay hoy. Es la diferencia que nadie nota hasta que abre otra sucursal y descubre quién entra.

`MembershipResource` expone además **todos** los roles de la persona y no sólo el activo por omisión: el rol por
defecto dice con cuál entra, la lista dice entre cuáles puede elegir, y son dos preguntas distintas. Sin ella, la
pantalla que administra roles no podía mostrar el estado actual. Se carga sólo en el detalle: en un listado de
cincuenta personas sería una consulta por fila para un dato que la tabla no muestra.

### D146 — Toda ruta de `/api/v1` aparece en al menos una prueba, y hay candado
**Estado:** Tomada · **Ámbito:** `tests/Architecture/EveryEndpointIsExercisedTest.php`

La revisión de cierre de la Iteración 2 preguntó qué endpoints **no se habían llamado nunca**. Salieron
**diecinueve de ciento uno**, y al llamarlos por primera vez aparecieron cuatro defectos más (D144, D147,
D148, D149). Dos de ellos llevaban una iteración completa en producción del repositorio.

La cobertura por módulo no detecta esto: un módulo con veinte pruebas y tres endpoints sin llamar se ve
igual de sano que uno completo. Y no es una aserción débil — es **cero** aserciones sobre código que
responde a internet.

El candado exige que la URI de cada ruta aparezca en el texto de las pruebas, emparejando los
`{parametro}` con lo que sea que la prueba interpole. **No** comprueba que la prueba sea buena: una ruta
mencionada en un `assertForbidden` cuenta. Es un piso deliberadamente bajo, y estuvo por debajo de cero
durante dos iteraciones.

Su meta-verificación arma la URI imposible por concatenación, porque escrita completa aparecería en el
propio archivo del candado —que también se escanea— y se contradiría a sí misma.

### D147 — La cantidad de una presentación de compra es inmutable
**Estado:** Tomada · **Ámbito:** `UpdateArticlePresentationRequest`

`quantity_in_base_unit` es el **divisor** con el que se calcularon los costos capturados a través de esa
presentación: «pagué $480 por la caja» se volvió `$0.0400/g` dividiendo por 12 000. Cambiarla a 24 000 no
corregiría un costo pasado — reinterpretaría todos, a la mitad de su valor, sin que ninguna fila del
historial de costos cambie ni deje rastro de por qué dejó de cuadrar.

Es exactamente el razonamiento de D96 para la unidad base y el del factor de conversión de una unidad; lo
que faltaba era aplicarlo aquí. El `PATCH` reutilizaba el Form Request del alta, así que la cantidad **sí
se podía cambiar** y nada lo impedía. Se descubrió al escribir la primera prueba que llamó al endpoint.

Se declara `prohibited` en lugar de ignorarse en silencio: quien la manda cree que va a cambiar algo, y un
cambio que se acepta y no ocurre es peor que un rechazo. Si el proveedor cambia el tamaño de la caja, es
otra presentación — dar de baja la anterior conserva la historia.

### D148 — Las rutas anidadas verifican la relación que la URL afirma
**Estado:** Tomada · **Ámbito:** `ArticlePresentationController`

`PATCH /articles/{a}/presentations/{p}` resolvía los dos parámetros **por separado**: comprobaba que cada
uno existe dentro del tenant y no que uno fuera del otro. Con eso, conocer el ULID de cualquier
presentación permitía editarla o darla de baja a través de la URL de otro artículo, y la respuesta era
**200**.

No es una fuga entre negocios —el scope de tenant sigue puesto— pero sí una escritura sobre un recurso que
el cliente no nombró: el jitomate podía cambiarle el nombre a la presentación del queso.

Se responde **404** y no 422: la presentación no existe *en esa ruta*, y es la misma respuesta que un ULID
inventado. Distinguirlas confirmaría que el recurso existe en otro sitio.

Es la única relación padre-hijo anidada del proyecto hoy; el patrón queda escrito para las que traiga
Inventarios.

### D149 — Un recurso recién creado se devuelve con la escala de la columna
**Estado:** Tomada · **Ámbito:** `UnitController`, `ModifierGroupController`

`POST /units` devolvía `factor_to_base: "12"` y cualquier lectura posterior `"12.00000000"`. Lo mismo con
`extra_price` de un modificador: `"28"` al crear, `"28.00"` al leer. El mismo valor con dos formas según el
endpoint obliga al cliente a normalizar cadenas de dinero por su cuenta, que es justo lo que se evita
mandándolas ya formateadas por la columna.

Un `refresh()` después de crear. Es la misma familia que D134 —consistencia de escala en la superficie de
la API— y salió también de la primera prueba que llamó al endpoint.

### D150 — P7 RESUELTA: la tasa de IVA no es por artículo en v1, y el riesgo queda registrado
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `Catalog`, y con vencimiento en la Iteración 7

La tasa se queda **por negocio con override por sucursal** (§6.1). Es lo correcto para un negocio de tasa
única, que es la mayoría, y evita agregar un campo que casi nadie usaría en v1.

**El riesgo, escrito para que nadie lo redescubra:** un negocio de **tasas mixtas** —alimentos preparados
al 16 % y despensa al 0 % en la misma cuenta— quedará con el desglose de IVA **mal calculado** en todos los
documentos que emita hasta que se agregue la tasa por artículo. Y eso **no se corrige con una migración**:
son documentos fiscales ya emitidos.

Concretamente, lo que habría que hacer si aparece ese cliente:

1. Columna `vat_rate` en `articles`, nullable, heredando la del negocio cuando es `null`.
2. Congelar la tasa en la línea del documento al emitirlo, como ya se congela el precio.
3. Los documentos anteriores **no se recalculan**: se quedan con la tasa que se les aplicó.

**Fecha límite de la decisión:** antes de emitir el primer documento fiscal, o sea antes de cerrar la
Iteración 7 (Clientes/CFDI-ready). Después de ese punto el costo deja de ser una columna y pasa a ser un
problema con el SAT.

Se recomendó no hacerlo en v1 y así se decidió. La alternativa considerada era agregarlo ahora, y su ventaja
—eliminar el riesgo para siempre por el precio de una columna— sigue siendo válida si el perfil de clientes
cambia antes de la Iteración 7.

### D151 — `auditable_ulid` en la bitácora: la evidencia se explica sola
**Estado:** Tomada (aprobada explícitamente, cambio del diseño del kernel) · **Ámbito:** `Audit`

La bitácora guardaba la llave interna de la entidad auditada, y esa llave tiene dos problemas: sólo
significa algo **mientras la fila exista** —borrada la entidad, el asiento apunta a la nada— y **no se puede
exponer** por la API (D91, §7: nunca IDs secuenciales). O sea que un asiento leído desde la API decía
«Article» y nada más.

Resolver el ULID al leer sería una consulta por fila sobre la tabla de mayor volumen del sistema, con un
`LEFT JOIN` distinto por cada tipo auditable. La columna lo hace innecesario, y además **congela** el
identificador: es lo que se le pide a una evidencia.

Índice `(tenant_id, auditable_ulid, created_at)` con su justificación: es la consulta «todo lo que le pasó a
ESTA entidad», la que se hace al investigar un caso —«¿quién le cambió el precio a las enchiladas?»— y la
única razón por la que la columna existe. `created_at` al final para que el orden descendente salga del
índice.

**Las filas anteriores se quedan en `NULL`, y es deliberado.** Se podría derivar el ULID de cada una y
rellenarlas, pero `audit_entries` es append-only por §7 y eso sería un `UPDATE` masivo sobre la tabla de
evidencia del sistema. Que el valor sea derivado no cambia la naturaleza de la operación: quien audite la
base después vería filas modificadas con fecha posterior a su creación, que es exactamente la señal que la
inmutabilidad existe para descartar. Los asientos viejos conservan su tipo y su llave interna, así que
siguen siendo rastreables desde la base; lo que no tienen es identificador público. Si alguna vez se quiere
rellenar, es una decisión propia.

Nota sobre el alcance de §7: **inmutable se refiere a las filas, no al esquema**. Agregar una columna no es
un `UPDATE` de registros. Aun así exigió aprobación explícita porque cambia el diseño del kernel.

### D152 — P2 RESUELTA: el inventario se valúa a ÚLTIMO COSTO, con el promedio como reporte
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `Inventory`, `Costing`

D14 fijaba «último costo + historial» para el **costeo de recetas**; valuar el inventario era otra pregunta y
no estaba decidida.

Se valúa a **último costo**, que es el vigente en `article_current_costs`. Razón principal, y no es de
eficiencia: con promedio ponderado habría **dos costos del mismo artículo** —el de valuación y el de costeo de
recetas— y la primera pregunta de cualquier dueño sería cuál es el bueno. Así, el valor del inventario y el
costo de los platillos hablan del mismo número.

El **promedio ponderado se calcula del kardex** cuando se pida, porque cada movimiento congela su `unit_cost`.
Es un reporte, no una segunda verdad.

Consecuencia de diseño: `article_stocks` **no** lleva `average_cost` ni `total_value`. Guardar el valor ahí
crearía una tercera fuente —además del kardex y del costo vigente— que se desviaría en silencio.

Si la contabilidad del negocio llegara a exigir promedio ponderado como método real, el cambio es concreto y
está escrito: `average_cost` en la proyección, recalculado en cada entrada **dentro del mismo lock**. Después
de tener movimientos, exige reconstruir valuaciones.

### D153 — P7 RESUELTA: confirmar una recepción de compra tendrá permiso propio
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `Purchasing`, catálogo de permisos

Capturar una recepción es teclear; **confirmarla mueve inventario y escribe en el historial de costos**, que es
irreversible. Son acciones de naturaleza distinta y compartían `purchasing.receipts.create`, o sea que quien
captura confirma — y la recepción de compra es justo donde entra el faltante que nadie revisó.

Se agregará `purchasing.receipts.confirm`. Es el **primer permiso nuevo** en el catálogo cerrado desde que se
sembró, y D72 lo permite explícitamente: cada iteración agrega los de su módulo. Lo que exige es que la matriz
de autorización lo cubra, y el candado de D128 lo obliga.

**Se agrega en el paso 9, junto con su ruta, no antes.** Un permiso en el catálogo sin endpoint que lo use es
exactamente el defecto de D140: un tenant lo concede y no pasa nada. Agregarlo hoy repetiría el error que la
revisión de la Iteración 2 acabó de encontrar.

### D154 — `balance_after` congelado, y un lock pesimista sobre la fila del saldo
**Estado:** Tomada (P1 aprobada) · **Ámbito:** `RecordStockMovement`, `stock_movements`, `article_stocks`

Cada movimiento congela el saldo que dejó. Da tres cosas: el kardex se lee como un **estado de cuenta** sin
acumular en el cliente, la proyección se vuelve **auditable** —si `article_stocks` no coincide con el
`balance_after` de su último movimiento, hay un problema visible— y el saldo de cualquier fecha se lee en una
fila en lugar de sumar la historia.

Su precio, dicho en voz alta: **obliga a serializar** las escrituras del mismo `(almacén, artículo, lote)`.
Calcular el saldo exige leer, sumar y escribir, y entre leer y escribir otro proceso puede hacer lo mismo: los
dos leerían el mismo saldo de partida y congelarían el mismo `balance_after`. El kardex quedaría afirmando que
el saldo es 30 cuando es 40. **No es un caso de laboratorio**: es un POS con dos cajas cobrando lo mismo a la
vez.

Se resuelve con `SELECT ... FOR UPDATE` sobre la fila de `article_stocks`, que existe gracias al índice único
de `(tenant, almacén, artículo, lot_key)`. Serializa **sólo** esa combinación: dos artículos distintos no se
esperan, y hay prueba de las dos cosas.

El saldo se lee de la **proyección** y no del último movimiento, porque la proyección es la que se puede
bloquear: `stock_movements` es append-only, no hay fila estable que tomar, y bloquear «el último movimiento» es
una carrera en sí misma. Usarla como punto de sincronización no la convierte en la verdad — se sigue pudiendo
reconstruir del kardex.

### D155 — Suite `Concurrency`, y una prueba de concurrencia que era FALSA
**Estado:** Tomada · **Ámbito:** `tests/Concurrency`, `phpunit.xml`, `tests/Pest.php`

`RefreshDatabase` envuelve cada prueba en una transacción, y una transacción hace los datos **invisibles** para
cualquier otra conexión. O sea que la herramienta que aísla las pruebas es exactamente la que impide verificar
un lock entre conexiones. De ahí una suite propia que hace `COMMIT` y limpia a mano; sólo van ahí las pruebas
que no se pueden escribir de otra forma.

**La primera versión de esa prueba era falsa, y conviene que quede escrito.** Tomaba el lock **ella misma**
desde una conexión y comprobaba que la otra se quedaba esperando. Pasaba en verde con el `lockForUpdate` del
servicio **borrado**: lo único que probaba era que MySQL bloquea filas, que no es algo que este código pueda
equivocarse.

Se descubrió haciendo lo de siempre —quitar el arreglo a propósito para ver si la prueba falla— y no falló.
Es el modo de fallo más peligroso de una prueba: verde, específica y sin verificar nada.

La versión correcta se cuelga del evento `created` del movimiento, que Eloquent dispara **dentro** de la
transacción del servicio: en ese instante el lock del servicio está tomado y desde la otra conexión se puede
observar. Es la única forma de mirar un lock que dura milisegundos. Verificado que muerde, con el mensaje
exacto.

### D156 — Una columna generada no puede basarse en una columna con `ON DELETE CASCADE`
**Estado:** Tomada · **Ámbito:** `article_stocks`

`lot_key` se genera desde `lot_id` para que la unicidad del saldo funcione con lotes nulos (patrón de D93). Con
`lot_id` declarado `cascadeOnDelete()`, MySQL rechaza la columna generada con **«1215 Cannot add foreign key
constraint»**.

`lot_id` pasa a `RESTRICT`, que además describe lo que ya era cierto: los lotes no se borran —pasan a
`depleted` o `expired`— y uno con saldo no debería poder desaparecer.

Queda escrito porque no se adivina y porque explica por qué D93 no tropezó con esto: en
`article_categories` la columna base ya era `RESTRICT`. Quien intente «arreglar» la asimetría volviéndola
`CASCADE` romperá la migración.

### D157 — Entrada, salida y ajuste son TRES tipos de movimiento, no uno
**Estado:** Tomada · **Ámbito:** `StockMovementKind`

El enum del paso 1 tenía sólo `manual_adjustment` para las tres cosas que el catálogo cerrado de permisos
distingue: `inventory.entries.create`, `inventory.exits.create` y `inventory.adjustments.create`. Con eso, los
tres permisos habrían acabado apuntando al mismo tipo y la distinción se habría quedado en la puerta sin llegar
al dato.

Y no es burocracia: son tres cosas que un negocio hace por razones distintas.

  - **Entrada manual:** entró algo que no fue compra — muestras del proveedor, una devolución.
  - **Salida manual:** salió algo que no fue venta ni merma — consumo interno, se lo llevó el dueño.
  - **Ajuste:** el sistema dice 10 y hay 8, y **no se sabe por qué**. Es la confesión de un descuadre.

Colapsarlas dejaba sin respuesta la pregunta que hace útil un kardex: «¿cuánto salió por consumo interno y
cuánto por diferencias que nadie explicó?». Con un solo tipo, las dos cifras son la misma.

Consecuencia: el ajuste —y sólo el ajuste— **exige nota escrita**. Los demás traen su explicación en el tipo o
en el documento origen; el ajuste no trae nada, y es justo el que más falta hace explicar. Meses después, un
descuadre sin nota no se puede atribuir a robo, error de captura o merma no registrada.

### D158 — Tres endpoints de escritura y no uno con un campo `kind`
**Estado:** Tomada · **Ámbito:** rutas de `Inventory`

`POST /stock-entries`, `/stock-exits` y `/stock-adjustments`, uno por permiso. El diseño de la iteración
proponía `POST /stock-movements` con los tres permisos anotados; al implementarlo resultó imposible y conviene
saber por qué:

  1. **`can:` recibe UN permiso.** Un endpoint único tendría que decidirlo leyendo el cuerpo, lo que lo dejaría
     sin permiso declarado en la ruta — o sea, **invisible para el candado de D129**, que es el que garantiza
     que ningún endpoint quede abierto. Cambiar el candado para acomodar el endpoint sería debilitar la defensa
     para salvar la forma.
  2. **Un `kind` libre en el cuerpo sería un agujero de dominio.** Permitiría registrar a mano un
     `sale_consumption` o un `transfer_out`, y esos **pertenecen a un documento**: un consumo por venta sin su
     cuenta como origen es un movimiento que nadie puede explicar después.

El tipo lo declara el Form Request de cada endpoint, no el cliente.

### D159 — El almacén tiene que estar al alcance de quien opera, y el central no tiene alcance
**Estado:** Tomada · **Ámbito:** `StockMovementController`

Mismo hueco que cierra `assertBranchInScope` en el catálogo: el `tenant_id` protege del negocio ajeno, **no** de
la sucursal ajena dentro del propio. Sin esto, un almacenista con alcance sobre una sucursal podría mover
existencias de otra, y el movimiento quedaría firmado con su nombre en un almacén al que no tiene acceso.

Un almacén **central** no pertenece a ninguna sucursal: surte a todas (D11), así que no hay alcance que
comprobar. Exigir una sucursal ahí lo dejaría inoperable para todo el mundo — y es el caso que se prueba
explícitamente, porque es el que se rompe al «endurecer» la regla sin pensar.

### D160 — `Inventory` depende de `Costing`, y es consecuencia de D152
**Estado:** Tomada · **Ámbito:** `config/comandia.php`, `RecordStockMovement`

Valuar a último costo exige leer el costo vigente, así que el módulo declara `depends_on => ['Catalog',
'Costing']`. El candado de fronteras (D92) lo impone; `Costing` nunca lee inventario, así que no hay ciclo.

La valuación vive en **un solo sitio** —el servicio de registro, dentro de la transacción y después del lock—
para que dos movimientos del mismo instante no se valúen con costos distintos por milésimas de segundo.

Si el artículo no tiene costo capturado, el movimiento queda **sin costo**: `null` y no cero. Cero diría que la
mercancía es gratis, y de ahí saldría un valor de inventario falso que nadie sospecharía.

### D161 — Dos filas de la matriz de autorización estaban mal, y la plantilla tenía razón
**Estado:** Tomada · **Ámbito:** `tests/Feature/Inventory/InventoryAuthorizationMatrixTest.php`

Al escribir la matriz exhaustiva de los dieciocho permisos, dos filas contradecían las plantillas de la
Iteración 1. Se revisaron las dos antes de tocar nada, y en las dos la plantilla tenía mejor argumento:

  - **El cajero NO ve existencias.** Yo había razonado que «¿queda pastel?» se pregunta en la caja. Pero el
    inventario del sistema es **teórico** (§6.2): el pastel que queda se ve en la vitrina, no en una pantalla
    que puede llevar tres días de atraso. Y enseñárselo a quien cobra invita a lo que §6.2 prohíbe — decidir
    una venta con un número de inventario, cuando la venta siempre procede.
  - **El almacenista SÍ ve precios de proveedor.** Los había reservado como información comercial. Pero es
    quien recibe la mercancía **con la factura en la mano**: ocultárselos en el sistema sería teatro, y de paso
    le impediría notar la subida que el catálogo de precios existe para detectar (D26). Misma lógica que le dio
    la captura de costos (D98). Lo que sigue sin ver es el margen.

Queda escrito donde ocurrió porque la próxima vez la tentación será la misma.

La matriz se escribió **en el paso 2**, no al final de la iteración: los dieciocho permisos ya existen en el
catálogo cerrado, así que repartirlos no dependía de que el código existiera. Su candado de cobertura es además
lo que garantiza que `purchasing.receipts.confirm` (D153) no llegue sin reparto en el paso 9.

### D162 — `articles.tracks_lots` vive en `Catalog` y no entra en `capabilities()`
**Estado:** Tomada · **Ámbito:** `Catalog`

La columna la pide `Inventory` —es él quien elige lotes al dar salida— pero la migración vive en `Catalog`,
porque cada módulo es dueño de sus tablas (§2). Una migración de `Inventory` alterando `articles` dejaría el
esquema de un módulo repartido entre dos carpetas.

**No entra en `capabilities()`**, y la distinción importa: las cuatro capacidades contestan **qué es** el
artículo —lo que se vende, lo que se inventaría, lo que se consume, lo que se produce— y de ellas dependen
invariantes del catálogo. Ésta contesta **cómo se controla la existencia** de algo que ya se decidió
inventariar. Meterla ahí obligaría a cada cliente a interpretar una bandera que no cambia lo que el artículo
es, y el día que haya tres banderas de inventario más, el grupo dejaría de significar algo.

Por omisión `false`, con CHECK de que sólo lo inventariable puede llevar lotes. Encender lotes para todo el
catálogo de golpe volvería el inventario impracticable en un negocio que hasta ayer no los usaba: activarlo
obliga a capturar el lote en cada recepción.

### D163 — El faltante de una salida FEFO va SIN LOTE, no al último lote usado
**Estado:** Tomada · **Ámbito:** `IssueStock`

Las existencias negativas están permitidas (§6.2), así que una salida mayor que lo disponible tiene que
proceder. La pregunta es a qué lote se le carga el faltante, y la respuesta es: **a ninguno**.

Cargarlo al último lote usado lo dejaría en negativo, y **un lote negativo ordena primero en FEFO**: absorbería
todas las salidas siguientes y el error se volvería permanente, empeorando solo. Cargarlo a la fila «sin lote»
hace tres cosas bien:

  - Los saldos por lote siguen diciendo la verdad — nunca bajan de cero.
  - El descuadre queda concentrado en un sitio visible, que es justo lo que el próximo conteo tiene que revisar.
  - Un saldo negativo «sin lote» en un artículo que sí lleva lotes es una señal legible por sí misma: salió
    mercancía que el sistema no supo atribuir.

### D164 — Una salida puede ser VARIOS movimientos, y la respuesta es siempre una lista
**Estado:** Tomada · **Ámbito:** `POST /api/v1/stock-exits`

Si el lote más próximo a caducar no alcanza, la salida se parte: 300 ml del lote de marzo y 200 del de abril son
**dos** renglones del kardex. Cada uno dice de qué partida física salió, que es exactamente lo que se necesita
para rastrear un lote defectuoso.

La respuesta es una lista **incluso cuando hay un solo movimiento**. Una forma que cambiara según cuántos lotes
había obligaría al cliente a manejar los dos casos, y el día que un artículo empiece a llevar lotes su
integración se rompería sin avisar. Entradas y ajustes siguen devolviendo un objeto: no se parten.

La llave de idempotencia se **sufija con el índice** del movimiento. Sin sufijo, el segundo chocaría con el
primero y se descartaría en silencio: la salida quedaría a medias y el saldo mal, sin que nada fallara.

### D165 — Los lotes que no caducan salen AL FINAL
**Estado:** Tomada · **Ámbito:** `IssueStock`, `ArticleLot::scopeFefo()`

La parte que no se adivina. En MySQL los `NULL` ordenan **primero**, y en PHP `null` compara como menor: en los
dos casos, un ordenamiento ingenuo por caducidad sacaría la sal —que no caduca— antes que la leche que vence el
jueves. Exactamente lo contrario de lo que FEFO quiere.

Hay desempate estable por `lot_id` para lotes que caducan el mismo día: sin él, el orden dependería de cómo
MySQL devolvió las filas y dos salidas idénticas podrían partirse distinto.

### D166 — Marcar un lote como caducado NO registra la merma
**Estado:** Tomada · **Ámbito:** `POST /api/v1/lots/{ulid}/expire`

El lote deja de surtir —FEFO lo salta— y **su saldo sigue ahí**. Es deliberado: dar la mercancía por perdida
automáticamente convertiría un vencimiento de calendario en una pérdida contable que nadie revisó, y en la
práctica muchas veces se revisa el lote y parte se salva.

La merma la registra una persona, con su motivo del catálogo y su umbral de autorización (D27). El sistema
señala; el humano decide — el mismo principio que el precio sugerido (D15).

Los endpoints de lotes existen además por otra razón: `inventory.lots.manage` llevaba dos iteraciones en el
catálogo cerrado **sin ruta**, que es el defecto que la revisión de la Iteración 2 encontró con otro permiso
(D140). Repetirlo a sabiendas habría sido peor que la primera vez.

### D167 — La segunda prueba de concurrencia también era falsa, y por otra razón
**Estado:** Tomada · **Ámbito:** `tests/Concurrency/FefoSelectionLockTest.php`

FEFO necesita **su propio lock**, distinto del de `RecordStockMovement`: ése protege la aritmética de una fila;
FEFO tiene un paso más —**decide de qué lote sacar**— y entre leer la disponibilidad y escribir, otro proceso
puede agotar el mismo lote.

La primera prueba observaba las filas del artículo completo mientras se registraba un movimiento. Pero en ese
instante `RecordStockMovement` ya tiene tomada la fila del lote que está escribiendo, así que la otra conexión se
bloqueaba **por el lock del registro** y no por el de FEFO. Pasaba en verde con el lock de `IssueStock` borrado:
no distinguía los dos locks, que era justo lo que tenía que distinguir.

Se descubrió igual que la vez anterior (D155): quitando el arreglo para ver si la prueba falla. No falló.

La versión correcta observa **un lote que FEFO decidió no usar**. `RecordStockMovement` nunca toca su fila, así
que si está bloqueada sólo puede ser por el lock previo de `IssueStock`. Verificado que muerde.

**Lección que ya va dos veces:** una prueba de concurrencia es fácil de escribir de forma que pase sin verificar
nada, porque el efecto que busca —un bloqueo— lo puede producir cualquier otra cosa del entorno. La única
comprobación que vale es romper el arreglo a propósito, y hay que hacerla **siempre**, no cuando queda tiempo.

### D168 — La merma es un movimiento con motivo, no un documento aparte
**Estado:** Tomada · **Ámbito:** `stock_movements.waste_reason_id`, `waste_reasons`

Se consideró una tabla `wastes` con sus renglones, como tendrán las recepciones y las transferencias. Se descartó:
una merma no tiene ciclo de vida —no se solicita, no se prepara, no se recibe— ni participantes. Es una salida que
ocurrió y hay que explicar.

Con documento aparte habría **dos cifras de la misma pérdida** —la suma del documento y la suma del kardex— y
tarde o temprano no cuadran. Con una columna en el movimiento, el reporte de mermas por motivo que D27 pide es un
filtro sobre el kardex y no hay nada que reconciliar.

Índice `(tenant_id, waste_reason_id, occurred_at)`: es exactamente la consulta del reporte —«mermas por motivo en
un periodo»— y es la única razón por la que el índice existe (§7 prohíbe índices sin justificar).

### D169 — El umbral de autorización se evalúa sobre el VALOR, no sobre la cantidad
**Estado:** Tomada · **Ámbito:** `inventory.waste_authorization_threshold`, `RegisterWaste`

Cien gramos de azafrán y cien kilos de sal no son la misma pérdida. Lo que el negocio quiere controlar es cuánto
dinero se va, así que el umbral está en pesos y hay que **valuar antes de decidir** si se pide autorización.

Ámbito **sucursal**, valor por omisión **500.00**. Cero haría que cada vaso roto necesitara el PIN de un gerente,
y el resultado previsible es que la gente deje de registrar mermas — que es peor que no tener umbral. Por sucursal
porque el volumen de un bar y de una fonda no se parecen.

**Consecuencia que hay que decir en voz alta:** un artículo **sin costo capturado no puede cruzar el umbral**,
porque su merma no vale nada calculable, y se registra sin autorización. Las dos alternativas son peores:
inventarle un costo de cero diría que la mercancía es gratis, y bloquear la merma dejaría al almacén sin poder
operar por un dato que le falta a otro módulo. La cobertura de costos es un problema de Costeo, no una razón para
detener el inventario.

La valuación se extrajo a `ResolveArticleCost` para que el costo del umbral y el costo que se congela en el
movimiento **no puedan divergir**: si fueran dos lecturas distintas, un cambio de política de costeo autorizaría
por una cifra y registraría otra.

### D170 — Falta de autorización es **409**, no 422
**Estado:** Tomada · **Ámbito:** `WasteRequiresAuthorizationException`, `InventoryServiceProvider`

Un 422 manda al usuario a revisar los campos, y ahí no hay nada que corregir: los datos son correctos y la
operación es legítima. Lo que falta es la firma de otra persona.

La respuesta lleva `type: authorization_required` y `required_permission`, para que la UI pueda abrir el diálogo
del PIN sin adivinar por el texto del mensaje. El mensaje dice el monto y el umbral, porque quien captura necesita
saber si le conviene corregir la cantidad o ir a buscar al gerente.

Es el primer uso de la autorización por PIN (ADR-008) **fuera de su propio endpoint**, y establece el patrón para
descuentos y cancelaciones en la Iteración 5: el cliente intenta, el servidor contesta 409 diciendo qué permiso
hace falta, el cliente pide la concesión y reintenta con el token.

### D171 — El catálogo de motivos comparte permiso con el registro de mermas
**Estado:** Tomada · **Ámbito:** `inventory.waste.create`

No se agrega un permiso para administrar motivos. Quien registra mermas necesita poder crear el motivo que le
falta **en el momento en que le falta**; obligarlo a pedirle a un gerente que dé de alta «se cayó al piso»
acabaría con todas las mermas bajo un motivo genérico, que es justo lo que D27 existe para evitar.

Es la misma lógica que las etiquetas del catálogo (D19): vocabulario libre del negocio, administrado por quien lo
usa. Lo que sí está separado —y con razón— es **autorizar** una merma sobre el umbral: el almacenista registra y
no autoriza, porque si quien registra pudiera autorizarse el umbral no defendería nada (D161).

Los motivos se dan de **baja**, no se borran: los movimientos que los citan tienen que poder seguir diciendo por
qué se perdió aquella mercancía. Un motivo inactivo sigue existiendo y deja de ofrecerse; capturar con él se
rechaza en el Form Request, porque un cliente con el selector en caché seguiría usándolo.

El nombre **sí** se puede corregir, a diferencia del código de un lote o la cantidad de una presentación: el
motivo no es divisor ni llave de nada, y corregir la ortografía no reinterpreta ninguna merma pasada.

### D172 — La merma es el único movimiento de inventario que también va a la bitácora técnica
**Estado:** Tomada · **Ámbito:** `AuditAction::WASTE_REGISTERED`

El kardex ya es evidencia inmutable, así que registrar cada entrada y cada salida en la bitácora produciría una
bitácora que nadie puede leer — y la haría inútil justo para lo que existe.

La merma es distinta: es una **pérdida con actor**, la zona de robo hormiga que §6.7 y §9 piden poder investigar.
El asiento guarda además el `authorized_by_membership_id`, que es la columna que distingue «lo hizo el gerente» de
«el gerente autorizó que lo hiciera otra persona». Sin esa distinción el reporte de §9 no se puede escribir.

### D173 — El motivo se escribe en un segundo paso, dentro de la misma transacción
**Estado:** Tomada · **Ámbito:** `RegisterWaste::stampReason()`

`IssueStock` no sabe de mermas y no debe saber: pasarle el motivo ensuciaría su firma y la de
`RecordStockMovement` con un concepto que sólo le importa a uno de sus llamadores. La tercera alternativa —que el
servicio de mermas escribiera el kardex por su cuenta— rompería la regla de que hay **una sola puerta de entrada**
al kardex, que es lo que hace confiable el saldo congelado.

Así que el motivo se escribe enseguida, por query builder, porque `stock_movements` es inmutable y el trait
bloquea `update()`. Es aceptable porque es la **escritura inicial** partida en dos, no una corrección de evidencia
ya registrada.

**Y al probarlo salió que la transacción sólo envolvía a `IssueStock`**, con un comentario que afirmaba lo
contrario. Un fallo entre las dos escrituras habría dejado en el kardex —que es inmutable— una salida sin motivo:
exactamente lo que §6.2 prohíbe, y sin forma de corregirla. Se movió `stampReason()` dentro de la transacción.

Lo anoto porque el error es del tipo que no falla nunca en pruebas: las dos escrituras van seguidas y nada entre
ellas falla jamás en un caso feliz. Lo encontré leyendo el código para escribir la prueba, no ejecutándolo.

### D174 — Verificar que la prueba muerde ya no es opcional
**Estado:** Tomada · **Ámbito:** proceso

Las quince pruebas de mermas pasaron en verde al primer intento, y eso es motivo de sospecha y no de celebración
(D155, D167 — dos pruebas falsas en la misma iteración). Se rompió a propósito el arreglo en los dos lugares
donde una prueba falsa costaría caro:

  - Sustituyendo el `throw` del umbral por `return null`: fallan **dos** pruebas, la del 409 y la del almacenista.
  - Cambiando el `pull` de la concesión de PIN por `get`: falla la de un solo uso.

Sin esta comprobación, la prueba «una merma sobre el umbral pide autorización» podría estar pasando porque el
artículo no tiene costo, porque el umbral se leyó mal, o porque el motivo estaba inactivo — tres razones que dan
el mismo verde y ninguna verifica el umbral.

### D175 — El conteo físico no tiene estado borrador
**Estado:** Tomada · **Ámbito:** `StockCountStatus`, corrección al §2.6 del diseño

El diseño proponía `draft → counting → closed | cancelled`. `draft` se quitó: su único contenido sería «existe el
conteo pero todavía no se congeló lo esperado», y no hay ningún momento del trabajo real que corresponda a eso.

Congelar es el equivalente a **imprimir la hoja de conteo**, y es lo primero que pasa. Separarlo del alta abriría
una ventana en la que la hoja que la gente lleva en la mano y lo congelado en la base pueden diferir — que es
precisamente el problema que congelar existe para evitar.

El alcance se elige al crear, en la misma petición: sin lista de artículos es un conteo general del almacén; con
lista, un conteo cíclico («hoy las carnes»). No hizo falta un campo `type` que los distinguiera — la lista misma lo
dice.

### D176 — Un solo conteo abierto por almacén, y es un índice único de verdad
**Estado:** Tomada · **Ámbito:** `stock_counts.open_warehouse_key`

Dos conteos abiertos del mismo almacén son un error de doble aplicación esperando a ocurrir: los dos congelan lo
esperado en 40, el primero cierra con 35 y aplica −5, y el segundo —que también dice 35— vuelve a calcular su
diferencia contra sus 40 congelados y aplica −5 otra vez. El saldo acaba en 30 y no se puede explicar mirando
ninguno de los dos conteos.

Se impone con el patrón de D93 invertido: una columna generada que vale `warehouse_id` sólo mientras el estado es
`counting`, y `NULL` en cuanto se cierra o cancela. MySQL no deduplica `NULL`, así que un almacén puede acumular
mil conteos cerrados y sólo uno abierto. La garantía es **estructural**, no una validación que una carrera pueda
saltarse — y el servicio comprueba primero de todos modos, para dar un mensaje útil en lugar de un error de índice.

**Lo que esto prohíbe, dicho claro:** dos personas no pueden contar en paralelo secciones distintas del mismo
almacén. Se acepta a cambio de la garantía; los conteos por secciones se hacen en serie, que es como se hace un
conteo cíclico de todos modos. Si algún día hace falta el paralelo, la evolución es cambiar la garantía por «un
artículo no puede estar en dos conteos abiertos», que es más preciso y ya no cabe en un índice.

**Consecuencia que obligó a añadir una ruta:** `POST /stock-counts/{ulid}/cancel`, que el diseño no listaba. Sin
cancelación, un conteo empezado por error dejaría ese almacén sin poder contarse nunca más.

### D177 — `counted_quantity` NULL no es cero
**Estado:** Tomada · **Ámbito:** `stock_count_lines`

`NULL` significa **«no se contó»**; cero significa «se contó y no había». La distinción es la más crítica de la
tabla: si `NULL` se tratara como cero, cerrar un conteo con la mitad de la hoja en blanco **borraría medio
almacén**.

Está expresado por la estructura y no por un `if`: `variance` es una columna generada, así que cuando
`counted_quantity` es `NULL` la diferencia es `NULL` también, y «no hay nada que aplicar» queda dicho por la base
de datos. Un `if` en el servicio funcionaría igual hasta el día que alguien escriba el segundo camino.

La columna no lleva `default`, a propósito: un default de cero es exactamente el error que borra el almacén.

Y la captura admite `null` como valor, que hace falta para **deshacer** un dedazo: sin él, una cantidad mal
teclada sólo se podría corregir por otro número y «no lo conté» sería inalcanzable.

### D178 — El conteo es CIEGO, y sale del reparto de permisos que ya existía
**Estado:** Tomada · **Ámbito:** `StockCountLineResource`, `StockCountResource`

Quien captura no ve `expected_quantity`, `variance`, el costo congelado ni la diferencia valuada. Los ve quien
tiene `inventory.counts.close`.

La razón es sencilla: si el almacenista lee «esperado: 40», escribe 40 y no cuenta. El conteo dejaría de ser
evidencia de nada y se volvería una confirmación de lo que el sistema ya creía — que es lo que §6.2 quiere
reconciliar.

**No es una regla nueva:** es el mismo control que §6.3 ya aplica al efectivo con `pos.blind_precount`, donde el
cajero declara su caja sin ver el monto esperado. Lo confirmé leyendo el catálogo de configuración *después* de
plantear la pregunta, y cambia el argumento: no es una decisión de inventarios, es la aplicación coherente de una
decisión que el proyecto ya había tomado.

La frontera coincide con una que ya estaba dibujada —el almacenista **cuenta** y no **cierra**— así que no hizo
falta un ajuste de configuración. Y un ajuste habría sido peor: un control que se puede apagar se apaga.

Cuesta algo, y conviene decirlo: quien captura no detecta su propio dedazo comparando con lo esperado. Lo detecta
quien revisa antes de cerrar, que es quien debe detectarlo.

Un conteo **cerrado** sí publica sus cifras a cualquiera que pueda verlo: el secreto sólo tenía sentido mientras
se contaba.

### D179 — Cerrar un conteo también tiene umbral de autorización, y lo firma el PROPIETARIO
**Estado:** Tomada · **Ámbito:** `inventory.counts.authorize_above_threshold`, `inventory.count_authorization_threshold`

El diseño no lo contemplaba, y era una incoherencia real: cerrar un conteo podía castigar cincuenta mil pesos de
inventario con menos control que una merma de seiscientos. `inventory.counts.close` dice **quién puede** cerrar; el
umbral dice **cuánto** puede absorber sin que nadie más firme.

Umbral propio, **5 000 por omisión** —un orden de magnitud sobre el de mermas— y no es arbitrario: una merma es un
evento (un vaso, una caja) y un conteo es el descuadre acumulado de semanas en un almacén entero. Con el mismo
umbral, *todo* cierre pediría el PIN del propietario y el control se volvería un trámite que se firma sin leer, que
es peor que no tenerlo.

**El autorizador es el propietario, no el gerente**, y es el único permiso del catálogo que se le quita al gerente
por esta razón (el resto de sus exclusiones son comerciales o de secreto financiero). Es el gerente quien cierra:
si además pudiera autorizar, se firmaría a sí mismo un castigo de cualquier tamaño. Con las mermas no hacía falta
—ahí registra el almacenista y autoriza el gerente— pero aquí quien ejecuta ya es el gerente y el control tiene
que subir un nivel.

Consecuencia operativa: un cierre con descuadre grande **espera al propietario**. No se pierde nada —el conteo
sigue abierto y lo capturado sigue ahí— y eso es exactamente lo que se quiere cuando se van a dar por perdidos
cincuenta mil pesos de mercancía.

Lo que este diseño **no** impide: que el propietario, cerrando él mismo, se autorice con su propio PIN. En un
negocio de una sola persona no hay alternativa —exigir un segundo actor lo dejaría sin poder cerrar nunca— y a
cambio queda el rastro: la bitácora dice que cerró y que autorizó, y son la misma persona.

### D180 — El umbral del conteo se mide en valor ABSOLUTO, y se guardan las dos cifras
**Estado:** Tomada · **Ámbito:** `stock_counts.variance_value`, `variance_value_absolute`

Un conteo con veinte mil de sobrante y veinte mil de faltante suma **cero neto** y reescribe **cuarenta mil pesos**
de inventario. Medir el umbral por el neto dejaría pasar sin que nadie lo mirara justo el caso que más urge
revisar: el descuadre grande que se compensa a sí mismo casi nunca es azar.

Por eso se congelan las dos cifras al cerrar, y son distintas a propósito:

  - `variance_value`, el **neto con signo**: el impacto contable, la cifra del negocio, la del listado.
  - `variance_value_absolute`, el **bruto**: cuánto inventario se reescribió. La cifra del control, la que se
    comparó con el umbral.

Guardar sólo el neto dejaría sin rastro auditable la decisión de pedir autorización; guardar sólo el bruto haría
ilegible el listado.

Las líneas sin costo capturado no suman a ninguna de las dos: su diferencia **sí** se aplica al kardex —la cantidad
es real— pero no vale pesos, y contarlas como cero las contaría como si no hubieran pasado. Misma consecuencia que
en las mermas (D169), mismo argumento.

### D181 — El costo se congela al abrir y es el mismo que usa el kardex
**Estado:** Tomada · **Ámbito:** `stock_count_lines.unit_cost_at_count`

`unit_cost_at_count` sirve para tres cosas —valuar el reporte, comparar con el umbral y valuar el movimiento de
ajuste que se escribe al cerrar— y las tres usan **ese** valor.

Si el cierre releyera el costo vigente, un cambio de costo entre la captura y el cierre haría que se autorizara por
una cifra y se registrara otra, y el conteo cerrado no cuadraría con sus propios movimientos. Es la misma razón por
la que las mermas extrajeron `ResolveArticleCost` (D169), aplicada un paso más allá.

Se añadió `ResolveArticleCost::currentForMany()` porque abrir el conteo de un almacén de doscientos artículos
producía doscientas consultas idénticas.

### D182 — Tres huecos de `ListQuery` que dos controladores habían parchado por su cuenta
**Estado:** Tomada · **Ámbito:** `app/Modules/Shared/Http/Query/ListQuery.php`

Los tres salieron al declarar el listado de conteos, y los tres estaban en el kernel desde la Iteración 1.

**1. El orden por omisión no admitía el prefijo `-`.** `defaultSort: '-started_at'` producía
`order by \`-started_at\`` y MySQL contestaba «Unknown column»: la única forma de declarar un listado descendente
por omisión era un 500 en producción, y quien lo intentara no tenía manera de sospecharlo leyendo la firma.

**2. Ningún orden desempataba.** Con dos filas del mismo valor, el orden lo decidía MySQL y podía cambiar entre
consultas idénticas — así que en un listado paginado una fila podía aparecer en dos páginas o en ninguna, porque la
página 2 se calcula con un orden distinto del de la página 1. No es raro: se descubrió con dos conteos abiertos el
mismo segundo, y pasa igual con dos artículos del mismo nombre o dos movimientos del mismo instante, que es lo que
hace un POS.

**3. Buscar en un listado sin columnas buscables se ignoraba en silencio**, devolviendo la lista completa a quien
había buscado algo concreto. Es el peor resultado posible porque **parece correcto**: el cliente cree estar viendo
coincidencias y está viendo todo. Ahora es 422, la misma regla que los filtros (§8): lo que no está declarado no
existe.

**Lo que hace interesante el hallazgo:** los huecos 1 y 2 estaban parchados, cada uno por su cuenta, en
`AuditEntryController` (un `reorder('created_at','desc')`) y en `PriceChangeController` (un `reorder()` con su
propio desempate por `id`). Los parches funcionaban y **eran justamente lo que mantenía invisible el hueco**. El de
la bitácora, además, tenía un efecto que su autor no buscaba: `reorder` descarta el desempate, así que el cursor de
la tabla más grande del sistema se paginaba con un orden ambiguo cada vez que dos asientos caían en el mismo
segundo — que en una bitácora es lo normal.

Los dos parches se quitaron y los dos listados ahora declaran su orden y nada más. Verificado que el arreglo del
kernel los reemplaza de verdad: quitar el desempate tira dos pruebas, y volver al comportamiento viejo del prefijo
tira catorce.

**Corrección a lo que escribí mientras lo arreglaba:** anoté en los dos controladores que sus listados «abrían por
la entrada más vieja». Era falso —los `reorder` lo corregían— y lo descubrí al leer el controlador completo en
lugar de sólo la línea que estaba cambiando. Queda anotado porque el error tiene una forma reconocible: encontré un
hueco real, y le atribuí de inmediato un síntoma que no había comprobado.

### D183 — Una prueba de 403 no ejercita el controlador
**Estado:** Tomada · **Ámbito:** proceso

El listado de conteos llamaba a `ListQuery::for()`, un método fluido que **no existe**: me lo inventé. Reventaba
con 500 en la primera llamada.

Mis pruebas del paso 5 sí llamaban a `GET /stock-counts`… en la prueba de que el mesero no puede verlo, que espera
un 403. El middleware cortaba antes del controlador, así que el cuerpo nunca se ejecutó y el 500 quedó escondido
detrás de una prueba en verde.

Lo encontró el candado de la búsqueda acentuada (D137), que llama a **todos** los listados con un término y sólo
mira que no revienten. Un candado escrito para otra cosa —colaciones ASCII— acabó siendo el que detecta endpoints
de lectura que nadie ejecuta nunca.

**La lección, para no repetirla:** una prueba de autorización verifica la ruta, no el controlador. Todo endpoint
necesita al menos una llamada que llegue al final. El candado de cobertura de D146 cuenta *llamadas*, no
*ejecuciones*, y esta clase de defecto se le escapa igual.

### D184 — La mercancía en viaje vive en un almacén de TRÁNSITO
**Estado:** Tomada · **Ámbito:** `warehouses.kind = 'transit'`, `TransferWorkflow`

Tercer valor del `kind` de almacén, uno por negocio, que **sólo escriben las transferencias**. Al enviar: origen
−100, tránsito +100. Al recibir: tránsito −95, destino +95. El residuo de 5 se convierte en merma **en tránsito** y
el saldo vuelve a cero.

La decisión se tomó porque las dos alternativas rompen algo concreto:

  - **Origen −100, destino +95, y nada explica los 5.** La pérdida quedaría documentada sólo en la transferencia y
    **no aparecería en el reporte de mermas**, que D168 definió como un filtro sobre el kardex. Se rompería esa
    promesa: habría pérdidas que el reporte de mermas no ve.
  - **Recibir los 100 en destino y mermar 5 ahí.** Cuadra aritméticamente y no toca `warehouses`, pero escribe en el
    kardex del destino una entrada de mercancía que nunca llegó y una merma que no ocurrió ahí. Quien audite ese
    almacén vería entrar algo que jamás entró, en la tabla que §7 declara evidencia inmutable.

Con tránsito, cada movimiento dice la verdad literal, nada desaparece, y «¿qué traigo en camiones?» es una consulta
normal de existencias en lugar de un reporte especial.

**Sin sucursal**, como el central y por una razón propia: la mercancía en viaje ya salió de una y todavía no llegó a
la otra, así que atribuirla a cualquiera de las dos sería falso. El `CHECK` de `warehouses` se amplió para
declararlo, en lugar de dejarlo como convención tácita — que es exactamente el argumento que la migración original
de la tabla ya usaba para justificar la columna `kind`.

**Uno por negocio, con índice único** (patrón de D93): dos repartirían la mercancía en viaje entre dos saldos y la
pregunta tendría dos respuestas.

**Se crea al primer uso, no al dar de alta el negocio.** Un oyente de `TenantProvisioned` dejaría fuera a los
negocios que ya existen y obligaría a una migración de relleno — que es el tipo de cosa que falla a medias en
producción. Resolver al primer uso es idempotente por construcción, y la unicidad no depende de ello porque la
garantiza el índice.

### D185 — La merma en tránsito NO va en el origen, contra lo que decía el diseño
**Estado:** Tomada · **Ámbito:** corrección al §2.7 · `TransferWorkflow::wasteInTransit()`

El §2.7 decía que la diferencia se atribuye «al almacén de **origen**, porque es de donde salió». Es incorrecto, y no
por matiz: **sería un doble cargo**. El origen ya bajó las 100 que subieron al camión; restarle otras 5 dejaría el
inventario 105 abajo cuando sólo se perdieron 5.

La merma va en tránsito, que además es donde se perdió: salió del origen y no llegó al destino.

Y el almacén de la merma no es lo que atribuye responsabilidad — eso lo dice la transferencia, que lleva origen,
destino y quién firmó cada paso. La preocupación legítima detrás del §2.7 («¿qué sucursal pierde mercancía?») se
contesta por el documento, no por el almacén del movimiento.

La merma **no pasa por `RegisterWaste`**: ese servicio existe para las mermas que una persona declara, con su umbral
y su PIN (D169). Ésta la declara el sistema al cuadrar dos cantidades, y pedir autorización por una diferencia que
el propio documento ya prueba dejaría mercancía en tránsito hasta que apareciera un gerente — y tránsito no es un
sitio donde algo pueda quedarse.

### D186 — `waste_reasons.is_system`: motivos que el negocio no administra
**Estado:** Tomada · **Ámbito:** `waste_reasons.is_system`, `UpdateWasteReasonRequest`

«Diferencia en tránsito» es del sistema. Si se pudiera renombrar a «se cayó al piso», las pérdidas del camión se
agruparían bajo un motivo que significa otra cosa y el reporte que D27 existe para dar quedaría mintiendo; si se
pudiera dar de baja, la siguiente recepción con diferencias fallaría.

No se renombra, no se da de baja, no se borra. La **exigencia de evidencia sí** se puede cambiar: es política del
negocio y no altera lo que el motivo significa. Es la misma distinción que los roles del sistema de la Iteración 1.

Defendido en dos capas, y las dos hacen falta: el invariante del modelo es la garantía —ningún camino lo salta— y la
validación del Form Request es la que produce un **422 con explicación** en lugar del 500 que sale de una excepción
de dominio sin mapear.

Lo encontré al escribir la prueba y marcarla `->throws()` para que pasara. Eso fue la señal de que el problema era el
código: una prueba que espera una excepción de una petición HTTP está describiendo un defecto, no un comportamiento.
Y `->throws()` a nivel de prueba además **oculta las aserciones anteriores** — la prueba pasaba lanzando en cualquier
punto, incluido uno mucho antes del que me interesaba.

### D187 — Tres cantidades por línea, y `NULL` no es cero en ninguna
**Estado:** Tomada · **Ámbito:** `transfer_lines`

`requested`, `shipped` y `received` contestan **«¿se pidió poco, se mandó poco o se perdió en el camino?»**, que es la
pregunta por la que existe el documento: las tres respuestas exigen acciones distintas —pedir mejor, surtir mejor, o
averiguar qué pasó en el camión— y confundirlas hace que nadie corrija nada.

`NULL` es «el paso no ocurrió»; cero es «se decidió no mandar nada» o «no llegó nada». La misma distinción que en el
conteo físico (D177) y por la misma razón. `transit_difference` es una columna **generada**, así que con
`received_quantity` en `NULL` la diferencia es `NULL` y «todavía no se sabe» queda dicho por la estructura.

**Se puede enviar menos de lo pedido, nunca más.** Para mandar más, se pide más: si no, la cantidad solicitada
dejaría de servir para distinguir «se pidió poco» de «se surtió poco». Y **no se puede recibir más de lo enviado**,
porque eso haría que el sistema inventara existencia que nunca salió de ningún lado.

### D188 — Los pasos omitibles se apagan por omisión, y la comprobación es por SELLO
**Estado:** Tomada · **Ámbito:** `inventory.transfers_require_authorization`, `..._require_preparation`

Por omisión el flujo es **solicitar → enviar → recibir**: tres pasos, cada uno con un hecho físico detrás. Autorizar
y preparar se activan cuando el negocio crece y aparece la bodega central con encargado.

Con los cinco activos desde el primer día, el caso común —una sucursal le presta un costal de arroz a otra— exigiría
cinco peticiones y probablemente dos personas, y lo previsible es que la gente deje de usar transferencias y registre
entradas y salidas manuales: se pierde el documento que las relaciona, que es lo único que la transferencia aporta
sobre dos movimientos sueltos.

Ámbito de **negocio** y no de sucursal: una transferencia tiene dos extremos, y si cada sucursal exigiera pasos
distintos no habría forma de saber cuál flujo aplica.

Activar los pasos después **no invalida las transferencias viejas**: sus sellos quedan nulos, y un sello nulo dice
«este paso no se pedía entonces».

**La obligatoriedad se comprueba por el sello, no por el estado.** Con las dos activas, preparar deja la
transferencia en `preparing`, así que al enviar el estado ya no dice nada de la autorización. El sello sí. Comprobar
el estado dejaría pasar cualquier transferencia preparada sin autorizar.

La máquina completa vive en `TransferStatus` y la configuración decide sólo qué pasos son **obligatorios antes de
enviar**, no qué transiciones existen: un enum que dependiera de la configuración dejaría de ser una declaración para
volverse una regla con estado.

### D189 — La cancelación se corta al enviar; el folio restringe las rutas central↔central
**Estado:** Tomada · **Ámbito:** `TransferWorkflow::cancel()`, `resolveFolioBranch()`

**Cancelar sólo antes de enviar.** Después, la mercancía está en un camión y el único cierre posible es recibirla —con
diferencias si hace falta. Cancelar una transferencia enviada exigiría deshacer movimientos de kardex, que es
imposible porque el kardex es inmutable.

**El folio sale de la sucursal del origen, o del destino si el origen es central.** §7 exige foliación por (tenant,
sucursal, tipo, serie) sin huecos, y un almacén central no tiene sucursal. Una transferencia entre **dos** almacenes
centrales no tendría ninguna y **se rechaza en v1** con un mensaje que dice qué hacer.

Es una restricción real: exige que el negocio tenga dos bodegas centrales, que es raro. Se aceptó porque la
alternativa —volver nulable `document_sequences.branch_id`— toca la tabla donde §7 es más explícito y obliga a
repetir el truco de la columna generada para que el índice único siga deduplicando. La evolución, si aparece la
necesidad, es una serie a nivel de negocio.

La sucursal del folio se **guarda** en el documento en lugar de recalcularse: si la regla cambiara, el folio de una
transferencia vieja dejaría de poder explicarse.

### D190 — El corte del almacén de tránsito vive en el trait compartido
**Estado:** Tomada · **Ámbito:** `AssertsWarehouseScope`

El almacén de tránsito no lo opera nadie. Y el paso 6 destapó que la salida temprana del trait —«sin sucursal, no hay
alcance que comprobar», escrita para los almacenes centrales— **lo dejaba pasar**: tránsito tampoco tiene sucursal.

Sin ese corte, una persona podía registrar entradas, salidas, mermas y hasta un **conteo físico** en el almacén de la
mercancía en viaje, y cualquiera de las cuatro dejaría mercancía sin dueño: lo que hay en tránsito tiene que cuadrar
con las transferencias abiertas.

Va en el trait y no en cada controlador porque ya son cuatro los que lo usan, y el día que se añada el quinto se
olvidaría — y el olvido sería del lado que autoriza de más. Los fallos de seguridad por duplicación no avisan.

Verificado que muerde: quitando el corte, la prueba que intenta las cuatro operaciones manuales falla.

### D191 — Candado: dos archivos de prueba no pueden declarar el mismo ayudante
**Estado:** Tomada · **Ámbito:** `tests/Architecture/TestHelperNamesAreUniqueTest.php`

Los ayudantes de un archivo de Pest son **funciones globales de PHP**. `TransferTest` declaró `saldo()` y
`StockMovementTest` ya lo tenía: el resultado no fue una prueba en rojo sino un `Fatal error: Cannot redeclare` que
**abortó la suite completa** antes de ejecutar nada — sin resultado que leer y sin pista de qué se rompió.

Lo peor es cómo se esconde: **correr el archivo solo pasa en verde**. El fallo aparece sólo cuando los dos archivos
entran en la misma corrida, que es al final de la entrega, cuando ya se había dado por bueno el paso.

El candado hace falta porque los nombres naturales en español se repiten —`saldo`, `merma`, `surte`, `existencia`— y
la señal que da el fallo es la más difícil de diagnosticar de todas. Lleva meta-verificación: si el recolector
dejara de encontrar declaraciones, la prueba pasaría sin mirar nada.

Verificado que muerde: reintroduciendo la colisión, nombra los dos archivos y explica el remedio.

### D192 — `recipe_snapshot_id` no congelaba nada: el snapshot son las líneas
**Estado:** Tomada · **Ámbito:** corrección al §2.8 · `production_order_lines`

El §2.8 proponía `recipe_snapshot_id → recipes` «porque las recetas cambian: sin él, un lote producido en marzo se
explicaría con la receta de agosto». El razonamiento es correcto y **la solución no funciona**: `recipes` es una fila
por artículo, mutable y sin versiones, así que la llave apunta a algo que puede cambiar mañana. Guardar el `recipe_id`
no congela la receta; sólo dice de cuál salió.

El snapshot real vive en `production_order_lines`, escritas **al completar**, y guardan los cuatro datos con los que la
orden se explica sin la receta: la cantidad **como estaba escrita** con su unidad, el `yield_percent` aplicado, lo que
de verdad se consumió en la unidad base, y con qué costo salió.

Sin los dos primeros el documento diría cuánto se consumió pero no **por qué esa cantidad**, y quien revisara un
consumo raro no podría distinguir «la receta pedía de más» de «alguien la cambió después».

`recipe_id` se conserva como referencia, nulable y `nullOnDelete`: RESTRICT bloquearía borrar una receta por una orden
vieja, y el snapshot sobrevive de todos modos.

### D193 — Las líneas se congelan al COMPLETAR, no al planear
**Estado:** Tomada · **Ámbito:** `ProductionWorkflow`

El momento del hecho físico es la producción, y la receta que lo explica es la que estaba en vigor entonces.
Congelarlas al planear haría que una orden que se queda tres días en borrador produjera con la receta de anteayer,
ignorando una corrección hecha ayer — y nadie lo notaría.

La contrapartida es que un borrador no tiene renglones que mostrar. Se resuelve **sin persistir nada**: la
previsualización de «qué va a consumir esto» se calcula de la receta vigente, y se inyecta en el recurso sólo cuando la
orden está abierta. En los listados no se calcula, porque sería una consulta de recetas por fila.

Y a diferencia del conteo (D175), aquí el borrador **sí tiene contenido**: producción planeada. «Mañana hacemos veinte
litros de salsa» es una decisión que se toma antes de tocar un ingrediente y sirve para saber qué comprar. La
diferencia entre los dos casos no es de gusto: el borrador de conteo no podía existir sin congelar lo esperado
—congelar era el primer acto— y el de producción no congela nada.

### D194 — La producción no explota la receta: consume el componente
**Estado:** Tomada · **Ámbito:** `ResolveProductionConsumption`

Si la masa es un artículo inventariable, producir salsa consume **masa** — no la harina y la levadura con las que se
hizo la masa. Ésas ya se consumieron cuando alguien produjo la masa, y explotar la receta hacia abajo las consumiría
**dos veces**.

De ahí que **no se reutilice el desglose de costeo para las cantidades**, aunque sería lo natural: la travesía es
distinta. El costeo *siempre* recursa, porque para valuar una salsa necesita el costo de su masa. La producción no debe
recursar nunca. Lo que sí se comparte son las piezas donde la aritmética podría divergir: el `UnitConverter` y el
`yieldDivisor()` de la línea de receta son los mismos objetos.

Y se ve funcionando en la prueba: la masa se **valúa** por su propia receta —el costeo deriva 0.06 el gramo de sus 2 g
de jitomate, y la captura manual de 0.50 no manda (D16)— y a la vez se **consume entera**. Las dos cosas a la vez, que
es exactamente la distinción entre costear y producir.

**Deuda declarada:** un componente producible que **no** se inventaría es una sub-receta de cálculo —existe para
costear, no tiene existencias— y consumirlo dejaría un saldo negativo creciendo para siempre en un artículo que nadie
mira. En v1 **se rechaza** con un mensaje que dice cómo arreglarlo: marcarlo inventariable, o sustituirlo por sus
insumos. La explosión selectiva es la evolución natural y se dejó fuera porque obligaría a que una misma orden tuviera
consumos de dos travesías distintas, con el mismo componente llegando por dos caminos y renglones cuyo origen ya no se
podría explicar.

### D195 — El rendimiento divide la cantidad FÍSICA, no sólo el costo
**Estado:** Tomada · **Ámbito:** `ResolveProductionConsumption`

D21 dice que el rendimiento divide, y en el costeo eso encarece la línea. Aquí saca **más mercancía del estante**: si
de cada kilo de jitomate sólo sirven 800 g, para tener 800 g utilizables hay que tomar un kilo.

Es el mismo divisor aplicado a dos cosas distintas, y las dos son ciertas. Verificado que muerde: quitando el divisor,
la prueba del 80 % falla.

Y el **escalado por unidad** es la otra mitad: la receta rinde en su unidad de salida y lo producido llega en la unidad
base del artículo. Una receta que rinde «1 L» producida en «500 ml» consume la mitad, no quinientas veces. Quitando esa
conversión fallan diez de las dieciocho pruebas del paso, que es la medida de cuánto sostiene.

### D196 — La valuación la decide el kardex; el documento congela el resultado
**Estado:** Tomada · **Ámbito:** `ProductionWorkflow`

No se pasa `unitCost` a ningún movimiento de la producción: cada uno se valúa por la puerta única del kardex, con el
costo vigente del artículo (D152).

Podría pasarse el costo recursivo que calcula el motor de costeo, y sería **peor**: el mismo componente quedaría
valuado de una forma en sus salidas por producción y de otra en todas sus demás salidas. Dos valuaciones del mismo
artículo son dos verdades.

Lo que sí se congela en el documento es el **resultado**: el costo unitario con el que entró el producible y el de cada
insumo que salió. Así la orden se explica sola dentro de un año, cuando los costos ya cambiaron.

El orden de escritura importa y es deliberado: primero las salidas de los insumos, después la entrada del producible.
Al revés, el saldo del producible subiría antes de que existiera con qué hacerlo, y el kardex se leería al revés de
como ocurrió.

### D197 — Permiso propio para producir, no el de entradas
**Estado:** Tomada · **Ámbito:** `inventory.production.create`

El §7 del diseño asignaba `inventory.entries.create` a los tres endpoints de producción. Se cambió: producir
**consume** inventario además de generarlo, así que reusar el permiso de entradas dejaría que quien sólo puede meter
mercancía la sacara — y por un camino que ni pasa por el endpoint de salidas, donde alguien lo estaría buscando.

Va al almacenista, al gerente y al propietario. No hay rol de cocinero en las plantillas de la Iteración 1; si
aparece, éste es su permiso.

Los insumos **no** se mandan en la petición: los dice la receta. Dejar que el cliente los eligiera permitiría producir
salsa consumiendo cualquier cosa, y la receta dejaría de significar algo.

### D198 — La producción no se bloquea por falta de insumos
**Estado:** Tomada · **Ámbito:** `ProductionWorkflow`

Misma regla que impide bloquear el POS (§6.2) y por el mismo motivo: la cocina hizo la salsa —está en la olla—
independientemente de lo que el sistema creyera tener. Bloquear no impediría la producción, sólo impediría
**registrarla**, y el resultado sería un inventario que se descuadra sin dejar rastro.

El saldo negativo es la señal de que el conteo va atrasado, no un error a esconder.

Y se pueden producir **menos** unidades de las planeadas, declarándolo al completar: el consumo se escala a lo que de
verdad salió. Sin eso, o se registra una mentira o no se registra nada. Es la misma distinción entre planeado y real
que la transferencia hace con sus tres cantidades (D187).

### D199 — El módulo `Purchasing` depende de `Catalog`, y de nadie más todavía
**Estado:** Tomada · **Ámbito:** `config/comandia.php`

Estaba declarado con `depends_on: []` desde la Iteración 1, y era falso en cuanto existiera: lee artículos y sus
presentaciones de compra para normalizar precios. Se corrigió a `['Catalog']`, que es lo que el candado de fronteras
(D92) impone.

Lee y **nunca escribe**: el artículo no sabe a cuánto se lo venden — eso vive en `Purchasing`. Es la misma frontera que
`Inventory` tiene con `Catalog`.

La conexión con `Inventory` y `Costing` llega en el paso 9 y será **por eventos**: confirmar una recepción emitirá un
evento del que `Inventory` registrará los movimientos y `Costing` capturará el costo con `origin = purchase` —el valor
del enum que existe desde la Iteración 2 esperando este momento. `Purchasing` no escribirá en ninguno de los dos
directamente, así que su `depends_on` no crecerá por eso.

### D200 — El RFC deduplica proveedores, y aquí NO hace falta el truco de D93
**Estado:** Tomada · **Ámbito:** `suppliers.rfc`

Dos proveedores con el mismo RFC son la misma persona moral capturada dos veces, y el síntoma es que las compras se
reparten entre dos fichas y «¿cuánto le compro a éste?» da la mitad. Así que el RFC es único por negocio.

Y es único **con un índice normal**, aunque la columna sea nulable. Conviene dejarlo escrito porque es justo lo
contrario de D93: allá el problema era que MySQL **no** deduplica `NULL` y hacía falta una columna generada para
forzarlo; aquí ese comportamiento es exactamente el que se quiere — muchos proveedores sin RFC (el puesto del mercado no
tiene) y ninguno repetido entre los que sí.

**Empecé escribiendo la columna generada por inercia del patrón.** Sobraba: el mismo patrón resuelve dos problemas
opuestos y hay que mirar cuál de los dos se tiene. Lo que sí hace falta es normalizar la cadena vacía a `null` en el
Form Request — sin eso, el segundo proveedor «sin RFC» sería rechazado por duplicado, un error incomprensible para
quien sólo dejó el campo en blanco.

El RFC se valida por **forma y no por validez fiscal**: 12 caracteres para persona moral, 13 para física. Implementar
el dígito verificador del SAT es una madriguera, y un RFC bien formado pero inexistente se descubre igual al facturar.
Lo que la regla evita es el dedazo evidente.

### D201 — El código del proveedor no cambia; el proveedor no se borra
**Estado:** Tomada · **Ámbito:** `Supplier`

El **código** es el identificador con el que la gente lo llama en papeles y conversaciones, y reasignarlo haría que los
documentos viejos parecieran ser de otro proveedor. Misma razón que el código de un lote (D23) o la unidad base de un
artículo (D96). Todo lo demás sí se corrige, **incluido el RFC**: se teclea mal, y corregirlo no reinterpreta ninguna
compra pasada — lo que la compra cita es el proveedor, no su RFC.

Y **no hay endpoint de borrado.** Las recepciones y el historial de precios lo citan, así que borrarlo dejaría compras
sin poder decir a quién se le compraron. Se da de baja con `status`, y las FK del historial son RESTRICT para que la
base lo impida además de la costumbre.

Un proveedor dado de baja **sigue consultable** —ése es el punto de darlo de baja— pero **no admite precios nuevos**: un
precio de alguien a quien ya no se le compra sólo puede ser un error de selección.

### D202 — `supplier_prices` es inmutable, y por eso contesta la pregunta
**Estado:** Tomada · **Ámbito:** `supplier_prices` · §7 actualizado

§6.2 lo pide «para comparación y detección de subidas», y con una sola fila por (proveedor, artículo) **ninguna de las
dos se puede contestar**: comparar exige varios proveedores y detectar una subida exige dos observaciones del mismo. Un
`UPDATE` sobre el precio anterior borraría precisamente el dato que responde.

Así que es un historial **inmutable** (§7, con el trait y en la lista del candado). Se corrige agregando una observación
nueva: si el precio se capturó mal, lo cierto es que hubo un error de captura esa fecha, y borrarlo hace que el
historial mienta sobre lo que se sabía entonces. No hay endpoint de edición ni de borrado — y la URI de una observación
individual devuelve **404, no 405**, porque no existe para ningún método: un 405 diría «esta dirección existe pero no
con PATCH», e invitaría a buscar el verbo correcto para algo que no se puede hacer de ninguna forma.

**Defecto que costó una corrida:** el trait `Immutable` apaga `$timestamps`, y con los timestamps apagados Laravel deja
de castear `created_at` por su cuenta. Sin declararlo a mano llega como cadena y el Resource revienta al pedirle un
formato. `ArticleCost` ya lo hacía desde la Iteración 2; se me pasó al copiar el patrón a medias.

### D203 — El precio se normaliza a unidad base al escribir, no al comparar
**Estado:** Tomada · **Ámbito:** `RecordSupplierPrice`

`unit_price` es **siempre por unidad base**. La factura dice «la caja de 12 kg a 480» y otro proveedor dice «el kilo a
42»: sin llevarlos al mismo terreno, el que vende en cajas grandes sale once mil veces más caro.

Es **la única puerta de entrada** al historial, por lo mismo que `RecordStockMovement` lo es al kardex: un segundo
camino que escribiera la tabla directamente dejaría observaciones sin normalizar que después se comparan como si lo
estuvieran.

Lo capturado se conserva aparte —`observed_quantity` y `observed_price`— porque es lo primero que alguien pide cuando la
comparación no le cuadra. Y la presencia de la presentación es lo que decide el modo: con ella el precio es **por
presentación**, sin ella ya viene **por unidad base**. No se admite ambigüedad, porque «3 cajas por 1440» sin decir si
es el total o la pieza es el error de captura más común de una factura, y adivinarlo produciría precios doce veces mal
que nadie sospecharía.

### D204 — La variación se calcula sobre el precio ANTERIOR, y las monedas no se mezclan
**Estado:** Tomada · **Ámbito:** `CompareSupplierPrices`

**Sobre el anterior:** subir de 10 a 15 es un 50 % de subida, no un 33 %. Dividir por el precio nuevo es el error que
hace que las subidas parezcan menores de lo que son, y es exactamente la razón por la que el cálculo vive en el servidor
y no en el cliente (regla 5 del Definition of Done).

Una sola observación devuelve `change: null` y no «0 %»: un cero afirmaría que el precio se mantuvo, y eso no se puede
afirmar con un solo dato.

**Las monedas no se mezclan.** En México se cotiza en dólares lo importado, así que la columna existe para que el dato
sea cierto. Lo que no hay es tipo de cambio, y no se va a inventar uno: la comparación agrupa por (proveedor, moneda),
de modo que el mismo proveedor puede aparecer en dos renglones. Mezclarlas daría una «bajada del 94 %» que sólo es un
cambio de divisa — y dos precios que no se pueden comparar bien es mejor que dos comparados mal, el mismo argumento que
el costeo usa cuando falta un costo.

El orden entre monedas es alfabético y no significa nada; ordenarlas juntas por precio insinuaría que sí.

### D205 — Ver precios de proveedor y capturarlos son permisos distintos
**Estado:** Tomada · **Ámbito:** rutas de `Purchasing`

Se **lee** con `purchasing.supplier_prices.view`, y lo tiene el almacenista: recibe la mercancía con la factura en la
mano, así que necesita poder comparar lo que le están cobrando (D161).

Se **captura** con `purchasing.suppliers.manage`, que es más restringido. La diferencia no es descuido: registrar una
cotización es tomar una posición sobre a quién comprarle, y eso es decisión de quien negocia. El catálogo cerrado no
tiene un permiso propio para esto y **no se agrega uno** — a diferencia de los motivos de merma (D171), aquí quien
captura NO es quien necesita el dato en el momento, así que la fricción no rompe nada.

`source = 'receipt'` **no se puede capturar a mano**: lo escribe el sistema al confirmar una recepción (paso 9).
Permitirlo dejaría marcar como «precio pagado» algo que nunca se pagó, y la comparación perdería su distinción más
útil — un hecho frente a una promesa.

**Cuarta aparición de la misma familia de defectos** (D134, D149, paso 4, y ahora aquí): sin `refresh()` tras el
`create`, los decimales vuelven como se mandaron —`480` en lugar de `480.0000`— porque Eloquent devuelve el atributo
asignado y no el que la base guardó. Vale la pena decir que ya van cuatro: es candidato a candado, y el que lo detecte
tendrá que comparar la respuesta de un `store` con una relectura del recurso.

### D206 — DECISIÓN DEL DUEÑO: el IVA de compras es acreditable por configuración, y el criterio se congela
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `purchasing.vat_is_creditable`

La factura dice «$100 + IVA = $116». El §3.2 no decía cuál de los dos es el costo, y la respuesta cambia el costo de
**cada artículo** y por tanto todos los márgenes.

Se decidió **configurable por negocio, con el acreditable por omisión**, porque en México los dos perfiles son reales:

  - Con IVA acreditable —el que emite factura— el impuesto se recupera contra el IVA cobrado, así que sumarlo al costo
    lo inflaría un 16 % y hundiría los márgenes.
  - Sin acreditar —RESICO, régimen simplificado, o quien compra en la central de abastos sin factura— el impuesto
    pagado **sí** es dinero que no vuelve, y entonces sí es costo.

**El DOCUMENTO no cambia con el ajuste.** La recepción guarda siempre la verdad de la factura: precio sin IVA por
línea, tasa por línea, impuesto calculado. Lo único que el ajuste decide es qué cifra se manda a `Costing`. Esa
separación es lo que permite cambiar la configuración sin volver ambiguo ningún documento.

**El criterio se CONGELA en cada recepción** (`vat_was_creditable`). Sin eso, cambiar el ajuste volvería inexplicable el
costo de las recepciones viejas: se vería el neto y el impuesto, y no cuál de los dos había ido al costo.

**RIESGO REGISTRADO, en la línea de D150:** cambiar el ajuste **no recalcula** los costos ya capturados, porque el
historial de costos es inmutable (§7). Un negocio que cambie de régimen quedará con dos criterios mezclados en su
historial. No es corregible con una migración —son costos con los que ya se valuaron ventas, mermas y producciones— y lo
correcto entonces es capturar costos nuevos, no esperar que los viejos se arreglen solos.

La tasa va **por línea** y no por documento: una factura mezcla tasas —alimentos preparados al 16 % y despensa al 0 %—
y la factura ya dice la de cada renglón, así que no hay nada que adivinar. Eso reduce del lado de las compras el
problema que D150 dejó abierto del lado de las ventas.

### D207 — El costo es por unidad BASE, no por unidad de captura
**Estado:** Tomada · **Ámbito:** `PurchaseReceiptLine::costPerBaseUnit()`

«3 cajas de 12 kg a $480» son $1 440 por 36 000 gramos: **$0.04 el gramo**. Confundir el precio de la caja con el costo
del gramo daría un valor de inventario **doce mil veces inflado**, y de ahí precios sugeridos absurdos.

Es la prueba central del paso, y la que más sostiene: rompiéndola —dividiendo por la cantidad de captura en lugar de por
la convertida— fallan **siete** de las veinticuatro pruebas.

Aquí se cobra lo construido en la Iteración 2: la presentación de compra existía desde entonces y ésta es la primera vez
que sirve para algo. Y la conversión se **congela** en la línea, porque la presentación puede darse de baja mientras el
movimiento tiene que seguir cuadrando con el saldo que produjo.

### D208 — Confirmar dispara tres efectos, todos por evento
**Estado:** Tomada · **Ámbito:** `PurchaseReceiptConfirmed` y sus tres oyentes

`Purchasing` no puede escribir en `Inventory` ni en `Costing` (ADR-001, la misma regla por la que el POS jamás escribe
en finanzas). Así que confirmar emite un hecho y cada módulo aplica lo suyo:

  1. `Inventory` registra el movimiento y **crea el lote** si hace falta.
  2. `Costing` captura el costo con `origin = purchase` — el valor del enum que existía desde la Iteración 2 **sin un
     solo llamador**, esperando este momento.
  3. `Purchasing` deja la observación de precio con `source = receipt`, el otro valor que el paso 8 declaró y dejó sin
     llamador.

**Síncrono y después del commit.** No en cola, a diferencia del descuento por venta: quien recibe mercancía tiene la
caja delante y necesita ver el saldo para decidir si la mete al estante. La asincronía de §6.2 existe para que el POS no
se bloquee, y una recepción no es el POS. Y después del commit porque si los oyentes corrieran dentro de la transacción,
el fallo de uno desharía la confirmación entera — con la mercancía ya en el estante.

**Los lotes se crean al confirmar, no al capturar.** Un borrador que ya creara lotes dejaría **lotes huérfanos** si nunca
se confirma, y un lote huérfano aparece en el selector de FEFO como si tuviera mercancía por surtir. Se reusa el lote si
ya existe con el mismo código: la misma partida puede llegar en dos facturas, y dos lotes homónimos repartirían su
existencia entre dos saldos.

### D209 — La dependencia de módulos se invierte, y el ciclo se rompe a mano
**Estado:** Tomada · **Ámbito:** `config/comandia.php`, `PurchaseReceiptLine`

Los oyentes obligan a declarar `Inventory → Purchasing` y `Costing → Purchasing`. La flecha **parece** invertida —lo
natural sería que compras dependiera de inventarios— y no lo está: lo que esos módulos conocen es el **evento**, no la
tabla. Compras no les escribe nada.

Pero había un ciclo real: `PurchaseReceiptLine` declaraba relaciones de Eloquent hacia `article_lots` y
`stock_movements`, así que `Purchasing → Inventory` **y** `Inventory → Purchasing`. Un ciclo entre módulos de dominio es
lo que ADR-001 existe para impedir.

Se rompió quitando las dos relaciones y dejando **sólo las FK**: la dependencia de datos es inevitable y deseable
—garantiza que el enlace apunte a algo real, igual que `recipe_lines.component_article_id`— y la de código desaparece.

Consecuencia aceptada: la recepción no muestra el lote resuelto por relación. Muestra el lote **como se capturó** —que es
lo que la factura decía— y `was_applied` contesta si el renglón llegó al kardex, que es la pregunta que de verdad
importa. Un renglón con cantidad y sin movimiento es una confirmación que se interrumpió.

### D210 — Una recepción confirmada se REVERSA, y la original no se toca
**Estado:** Tomada · **Ámbito:** `purchase_receipts.reverses_receipt_id`, `StockMovementKind::PurchaseReturn`

§3.2 lo pedía y hacía falta un tipo de movimiento nuevo: `purchase_receipt` tiene dirección fija de entrada, así que una
reversa no podía usarlo. Se agregó **`purchase_return`** —«la mercancía volvió al proveedor»— y no se usó
`manual_adjustment`, que admite las dos direcciones, porque un ajuste significa «salió algo y nadie sabe por qué»
(D157) y aquí la razón se conoce.

Sale al **costo con el que entró**, congelado en la línea. Valuarla al costo vigente daría una devolución que gana o
pierde dinero según cuándo se haga.

**La original no se marca**, ni siquiera con un estado `reversed`: eso sería mutar un documento confirmado. El enlace
vive en la reversa, y «¿está reversada?» es una consulta — con índice único, así que se reversa **una vez** y la
garantía no depende de una comprobación que una carrera pueda saltarse.

**La reversa no captura costo ni observación de precio.** Una devolución no fija precio: la mercancía se fue, no llegó.
Y el costo que la recepción capturó **no se borra**, porque mientras estuvo vigente se valuaron movimientos con él —
ventas, mermas, producciones— y borrarlo volvería inexplicables esas valuaciones. Si el costo hay que corregirlo, se
captura uno manual: es un hecho distinto y honesto, con su actor y su fecha.

Una reversa no se reversa: para volver a meter la mercancía se captura una recepción nueva, con el precio y la fecha
reales en lugar de copiar los de hace un mes.

### D211 — Confirmar es un permiso aparte de capturar (D153 cerrada)
**Estado:** Tomada · **Ámbito:** `purchasing.receipts.confirm`

El permiso que D153 dejó comprometido para este paso, y aquí nace **con su ruta** — que era la mitad del compromiso, por
el defecto de D140: un permiso del catálogo sin ruta.

`receipts.create` captura y cancela borradores; `receipts.confirm` aplica al inventario y al historial de costos. **No lo
tiene el almacenista**, aunque capture las recepciones: confirmar mueve existencia y **fija el costo** del que salen
todos los precios sugeridos y todos los márgenes. Es la misma frontera que cerrar un conteo (D179) — quien tiene la
mercancía delante captura el documento; aplicarlo es de quien responde por el inventario.

### D212 — SUSTITUYE una decisión de la Iteración 2: la llave de idempotencia del costo ahora devuelve en lugar de lanzar
**Estado:** Tomada · **Ámbito:** `CaptureArticleCost`, `tests/Feature/Costing/ArticleCostCaptureTest.php`

**Corrección a la primera versión de esta entrada:** la escribí diciendo que la idempotencia del costo «faltaba desde la
Iteración 2» y que era un descuido. **Era falso.** La Iteración 2 lo eligió a propósito y lo dejó escrito en su prueba:

> «el índice único lo hace imposible aunque el código se equivoque, que es la diferencia entre una garantía y una buena
> intención»

Lo descubrí porque esa prueba falló al correr la suite completa. Si me hubiera fiado de mi propia lectura del código sin
mirar sus pruebas, habría cambiado una decisión ajena presentándola como un arreglo.

**Qué se cambia y por qué, ahora dicho con honestidad.** El argumento de la Iteración 2 es bueno y su garantía sigue
intacta: el índice único no se toca. Lo que se corrige es el comportamiento del **servicio**.

Una llave de idempotencia significa «esta operación se identifica así; aplicarla dos veces tiene que tener el efecto de
aplicarla una». Bajo ese contrato, reintentar y recibir el resultado que ya existe **es** lo correcto, y lanzar es una
implementación incompleta: obliga a cada llamador a atrapar la excepción y a reconocer códigos de error de MySQL para
distinguir un reintento normal de un fallo real.

Lo destapó el paso 9: al confirmar una recepción, re-despachar el evento reventaba con un 500 en el costo mientras el
movimiento de kardex lo soportaba sin problema. **Dos mecanismos de idempotencia del mismo proyecto comportándose
distinto** es la trampa en la que cae quien escriba el tercero, y por eso se unificó en lugar de parchar el oyente.

Se traga el duplicado **sólo** cuando el llamador puso llave: sin ella, una violación de unicidad sería cualquier otra
cosa y esconderla dejaría un fallo real sin diagnosticar. El detector del duplicado está escrito igual en los dos sitios.

**La prueba de la Iteración 2 no se borró, se partió en dos** — porque defendía dos cosas y sólo una cambió:

1. El servicio es idempotente: reintentar devuelve la misma fila, y una sola.
2. La garantía sigue siendo de la base: un **segundo camino** que escriba `article_costs` sin pasar por el servicio se
   topa con el índice. Eso es literalmente el «aunque el código se equivoque» del argumento original, y ahora se
   comprueba sin pasar por el servicio en lugar de a través de él.

**Consecuencia que hay que dejar escrita:** reintentar con la misma llave y **datos distintos** ahora se ignora en
silencio — devuelve la fila vieja. Es un error del llamador, y ninguno de los dos comportamientos lo detecta bien (lanzar
lo detectaba por accidente). La llave identifica la operación; si los datos cambian, es otra operación y le toca otra
llave. La prueba lo afirma explícitamente para que nadie se sorprenda.

**Lección, que vale más que el arreglo:** una columna de idempotencia no es una garantía de idempotencia. La garantía es
el manejo del conflicto, y la única forma de saber que existe es reintentar en una prueba.

**Y una segunda lección, sobre el proceso:** antes de «arreglar» algo de una iteración anterior, hay que leer sus
pruebas. El código no dice por qué; la prueba sí.

### D213 — La misma factura del mismo proveedor no se captura dos veces
**Estado:** Tomada · **Ámbito:** `purchase_receipts` índice único, `StorePurchaseReceiptRequest`

Es el error de captura **más caro de todos**: duplica existencia, duplica costo y descuadra el inventario contra la
realidad sin que nada avise. Lo impide un índice único por (negocio, proveedor, folio de factura) — nulable, porque el
puesto del mercado no da factura, y MySQL admite tantos `NULL` como haga falta.

Y también una regla en el Form Request, que **no es redundancia inútil**: el índice lo rechazaría como un 500, y quien
lo intenta merece saber que esa factura ya está capturada. La primera versión de la prueba esperaba el 500 y estaba
describiendo el defecto en lugar del comportamiento.

### D214 — El folio de una recepción en almacén central sale de la sucursal de quien recibe
**Estado:** Tomada · **Ámbito:** `PurchaseReceiptWorkflow::resolveFolioBranch()`

§7 exige foliar por sucursal y un almacén central no tiene ninguna (D11). En las transferencias eso se resolvió con el
otro extremo (D189); aquí no hay otro extremo.

**La primera versión rechazaba las recepciones en almacén central, y estaba mal** — no por matiz: recibir en la bodega
central es el caso NORMAL de una cadena, precisamente el negocio que más lo necesita. Bloquearlo por un detalle de
foliación es la cola moviendo al perro.

La sucursal activa de quien recibe es la respuesta correcta y no un parche: el documento lo archiva la sucursal que
recibió la mercancía, que es la que va a conciliarlo con la factura. Sale del contexto de la petición, así que no hay
nada que preguntarle al cliente.

Sólo queda sin foliar el caso en que ni el almacén ni la persona tienen sucursal —una membresía con acceso a todas
operando sobre un central— y ahí sí hay que elegir: elegir por ella sería inventar el archivo del documento.

### D215 — El barrido de aislamiento usa los CAMINOS, no los modelos
**Estado:** Tomada · **Ámbito:** `tests/Feature/Inventory/InventoryTenantIsolationTest.php`

Catorce tablas de `Inventory` y `Purchasing`, con las cuatro comprobaciones del barrido de `Catalog`: invisibilidad,
autoverificación, simetría y cobertura. Obligatorio en la definition of done de cada módulo (§11).

Lo que lo distingue del de `Catalog` es que **casi nada se crea por factory**. No por falta de factories: estos dos
módulos no tienen una sola tabla que se escriba a mano —el kardex tiene una puerta única, el conteo se cierra por su
flujo, la recepción se confirma por evento— y crear las filas directamente comprobaría el aislamiento de los **modelos**
en lugar del de los **caminos**, que es donde una consulta cruzada se cuela.

La fila más valiosa del barrido es la línea de recepción, porque se **confirma**: eso dispara los tres oyentes, dos de
ellos en otros módulos. Un oyente que corriera sin contexto de negocio escribiría en el tenant equivocado, y es el fallo
más difícil de ver de todos — ocurre después del commit y fuera del servicio.

Dos casos se probaron aparte porque son los que un scope mal escrito dejaría pasar:

  - **El almacén de tránsito**, que no pertenece a ninguna sucursal (D184). Un scope que se apoyara en la sucursal para
    acotar lo dejaría compartido entre negocios — y ahí vive la mercancía en viaje de todo el mundo.
  - **El motivo de merma del sistema**, que lo crea el sistema y no la persona. Si fuera global, el reporte de mermas de
    un negocio agruparía las del otro.

Verificado que muerde: neutralizando el `where` del `TenantScope`, cinco de las seis pruebas fallan.

**Defecto que el barrido destapó al primer intento:** `Supplier::create()` sin `status` dejaba el atributo **nulo en
memoria** —la columna tiene su default en la base, pero el modelo no lo sabe hasta releerse— y `isActive()` reventaba con
«call to a member function on null». Se arregló con `$attributes` en el modelo y no en cada llamador, porque «un
proveedor nace activo» es una decisión del dominio y no del sitio que lo crea. `WasteReason` tenía lo mismo y se corrigió
igual.

### D216 — Candado: todo oyente registrado, todo evento despachado
**Estado:** Tomada · **Ámbito:** `tests/Architecture/ListenersAreRegisteredTest.php`

Un oyente sin `Event::listen` **no falla: no corre.** El código existe, se ve bien, y en producción la mercancía no entra
al kardex, el costo no se captura, y nadie ve un error.

En la Iteración 3 eso pasó a ser un riesgo real: confirmar una recepción tiene **tres** efectos y cada uno vive en un
oyente distinto, dos de ellos en otros módulos. Las pruebas de recepción lo habrían atrapado sólo porque comprueban los
efectos — una escrita comprobando la respuesta HTTP no habría notado nada.

Y al revés también: un evento que nadie despacha es código muerto que parece vivo, porque alguien le escribirá un oyente
y esperará que corra. La regla es «se despacha», no «se escucha», porque `StockMovementRecorded` se emite desde el primer
día **sin suscriptores a propósito**.

Verificado que muerde: quitando el registro del oyente del costo de compra, el candado nombra el archivo exacto.

### D217 — Candado: un servicio devuelve lo que la base tiene
**Estado:** Tomada · **Ámbito:** `tests/Architecture/CreatedModelsAreRefreshedTest.php`

El defecto que D205 dejó anotado como candidato a candado, y que ya había aparecido **cuatro veces** (D134, D149, y los
pasos 4 y 8 de esta iteración): `Modelo::create(['quantity' => '1000'])` devuelve `'1000'` y no `'1000.0000'`, porque
Eloquent devuelve el atributo **asignado** y no el almacenado.

**Al escribirlo esperaba una lista corta y encontró diez servicios.** La primera reacción fue pensar en excepciones —«en
éste el controlador ya relee, en aquél no hay decimales»— y habría sido el error: una lista de excepciones larga es una
lista que nadie lee.

Mirándolo otra vez, la razón para arreglar los diez es **más fuerte que el problema de los decimales**: este proyecto usa
columnas generadas por todas partes —`variance`, `transit_difference`, `lot_key`, `balance_after`— y una columna generada
**nunca** está presente en un modelo recién creado. Ni con el valor viejo: no existe como atributo.

Así que la regla no es «releer cuando haya decimales», es **un servicio devuelve lo que la base tiene**. Sin
condiciones, sin excepciones que recordar, y con un costo de un `SELECT` por escritura que a esta escala no se nota.

El candado es análisis de texto y reconoce las dos formas que produjeron los defectos. Lleva meta-verificación doble: que
encuentra servicios, y que el patrón **reconoce un `create()` cuando lo hay** — sin lo segundo, una expresión regular
mal escrita daría el mismo verde silencioso.

### D218 — Las garantías estructurales se prueban SIN pasar por la aplicación
**Estado:** Tomada · **Ámbito:** `tests/Feature/Inventory/StructuralGuaranteesTest.php`

La Iteración 3 apoyó siete invariantes en restricciones reales de MySQL. Todas tenían su prueba «por la puerta» —el
endpoint devuelve 422 con un mensaje útil— y **esas pruebas no comprueban la garantía: comprueban la cortesía.**

Una comprobación de aplicación tiene dos agujeros que un índice no tiene:

  1. **La carrera.** Entre leer «¿ya hay un conteo abierto?» y escribir cabe otra petición.
  2. **El segundo camino.** Un seeder, una migración de datos, un job futuro escrito de prisa. Ninguno pasa por el Form
     Request.

Así que estas once pruebas escriben **directo por el modelo**, saltándose el servicio, y afirman que la base rechaza:
un conteo abierto por almacén (D176), un tránsito por negocio (D184), una reversa por recepción (D210), una factura por
proveedor (D213), la dirección que corresponde al tipo, la transferencia a sí misma, y la producción de cero.

Es el mismo argumento con el que la Iteración 2 defendió la idempotencia del costo, y que D212 tuvo cuidado de no perder.

**Y cada garantía viene en pareja con su contraparte**: que lo que debe caber, cabe. Muchos conteos cerrados, muchas
recepciones sin reversar, la misma factura de otro proveedor, el ajuste en las dos direcciones. Un índice demasiado
estricto no falla en las pruebas de rechazo — falla rechazando operaciones legítimas, y eso es más difícil de ver.

### D219 — Un permiso agregado después del alta NO existía para los negocios ya creados
**Estado:** Tomada · **Ámbito:** `comandia:permissions:sync`

Los permisos del catálogo cerrado (D10) se siembran una vez, y los roles de plantilla se escriben **al dar de alta el
negocio**. Así que un permiso agregado en una iteración posterior **no existe como fila** en `permissions` para una
instalación que ya estaba corriendo, y por tanto ningún rol lo tiene — ni el del propietario.

El síntoma: la ruta protegida devuelve **403 para todo el mundo, para siempre**, sin que nada avise. En este paso lo vi
como un botón que simplemente no aparecía: «Confirmar recepción» estaba oculto porque
`purchasing.receipts.confirm` —agregado en el paso 9— no existía en la base del negocio de demostración, creado antes.

Y afectaba a **los tres permisos** que esta iteración agregó: `inventory.counts.authorize_above_threshold`,
`inventory.production.create` y `purchasing.receipts.confirm`. O sea que en cualquier instalación real, cerrar un conteo
grande, producir y confirmar una recepción eran imposibles.

**La suite no podía verlo.** Cada prueba da de alta un negocio nuevo, con el catálogo del día. El defecto vive
exactamente en el hueco entre «se dio de alta con la versión vieja» y «se actualizó el código», que es el estado normal
de cualquier instalación que lleve tiempo funcionando. Lo encontró el navegador, y sólo porque el negocio de
demostración era viejo.

El comando siembra lo que falte y vuelve a correr `ProvisionTenantRoles` en cada negocio, que ya era idempotente. No
borra los permisos que estén en la base y ya no en el catálogo: un permiso retirado puede seguir citado por un rol que
el negocio armó, y borrarlo dejaría ese rol sin poder explicar qué permitía.

**PREGUNTA ABIERTA para el dueño del producto.** El comando re-sincroniza sólo los roles de **sistema** —el del
propietario— porque los editables son del negocio y reponerlos desharía su configuración. La consecuencia: un permiso
nuevo **no llega** a gerente, cajero, mesero ni almacenista, aunque `RoleTemplates` diga que les corresponde. Un negocio
que nunca tocó esos roles esperaría que los valores por omisión siguieran funcionando, y no lo hacen. Hay tres caminos y
ninguno es obviamente correcto: repartir a los que el negocio nunca editó (haría falta saber si los editó), avisar en la
pantalla de roles, o dejarlo manual como está.

### D220 — Lo que encontró abrir el navegador, y que la suite no podía ver
**Estado:** Tomada · **Ámbito:** paso 11

Cinco defectos, y ninguno era detectable con pruebas de API. Se listan juntos porque comparten la lección.

**1. `useApiForm` descartaba lo que producía el callback.** Devolvía siempre `true`, así que
`const creado = await save.submit()` daba `true` y `creado.ulid` era `undefined`. La pantalla navegaba a
`/recepciones/undefined`, la ruta no coincidía por su restricción de ULID, y **no pasaba nada**: ni error, ni navegación.
Ahora devuelve el valor del callback, o `true` cuando no produce nada — que mantiene el contrato de los diez llamadores
que ya existen.

**2. La presentación se autoseleccionaba de forma asíncrona.** El renglón aparecía diciendo «Precio sin IVA **por g**» y,
cuando volvía la petición de presentaciones, la etiqueta pasaba a «por presentación» — **cambiando el significado del
campo bajo las manos de quien escribía**. Capturé 5000 leyendo «por g» y el documento guardó 60 000 000 g. Se arregló
pidiendo las presentaciones **antes** de agregar el renglón: no hay carrera cuando nadie está leyendo la pantalla, y por
eso la suite no lo veía.

**3. Un precio que no cabe en la columna bloqueaba la confirmación.** `supplier_prices.unit_price` es `DECIMAL(12,4)`, así
que un renglón cuyo precio por unidad base quede por debajo de 0.00005 llega como `0.0000` — y `RecordSupplierPrice` lo
rechaza, con razón para la captura a mano. Pero en el oyente no hay nada que corregir: la factura es correcta y el número
derivado no cabe. Ahora se **omite la observación** y se deja dicho en el log, que es la regla que el proyecto aplica en
todas partes: mejor ninguna cifra que una cifra falsa.

**4. Y el peor: un fallo de oyente hacía que la confirmación mintiera.** La transacción cierra antes de despachar el
evento (D208), así que cuando el tercer oyente lanzó, la petición respondió **422** y la base tenía la recepción
**confirmada**, con su movimiento en el kardex y su costo capturado. Quien confirmó creyó que no había pasado nada — la
peor mentira que puede decir una interfaz, porque invita a repetir la operación.

Ahora el fallo se registra y **no se propaga**. No es tragarlo: queda en el log con el documento y el oyente, el estado
incompleto es detectable desde el propio documento (`was_applied` por renglón), y como los tres efectos son idempotentes
por llave, volver a despachar el evento repara lo que falte. Meter los oyentes en la transacción sería peor y ya estaba
descartado: un fallo del tercero desharía una entrada de mercancía que físicamente ya ocurrió.

**5. `comandia:demo:seed --fresh` ya no podía purgar.** La lista de tablas a borrar está escrita a mano en orden inverso
a las dependencias, y no conocía las **once** tablas de esta iteración: los cuatro renglones de documento apuntan a
`stock_movements` con `RESTRICT`, así que borrar el kardex antes de ellos fallaba. El mensaje era un error de clave
foránea de MySQL sin pista de qué faltaba, y aparecía justo cuando alguien quisiera preparar una demo.

Ahora hay una prueba que siembra y vuelve a sembrar de verdad. **Escribí además un segundo candado que estaba mal** y lo
quité: exigía que *toda* tabla acotada estuviera en la lista, y encontró diez «faltantes» de iteraciones anteriores que
no faltaban —su FK a `tenants` es `CASCADE`, se van solas—. Un candado que pide trabajo inútil se acaba apagando, y
cuando alguien lo apaga se lleva por delante al que sí servía.

**La lección, que es la que el usuario ya había dejado escrita:** la suite en verde no basta para dar por hecho el
frontend. Y ahora está medida: 842 pruebas verdes y cinco defectos vivos, uno de ellos capaz de decirle a alguien que su
compra no se registró cuando sí.

### D221 — El costo de una compra se sella al confirmar, no a la medianoche del día
**Estado:** Tomada · **Ámbito:** `CaptureCostFromPurchaseReceipt`

`received_at` es una **fecha** a propósito —una recepción es de un día (§3.2)— y sellar el costo con su `startOfDay()`
hacía que la compra quedara siempre por detrás de **cualquier** costo capturado más tarde ese mismo día, incluidos los
capturados antes de que la mercancía llegara.

Eso no era una política de precedencia: era la precisión de la columna decidiendo por su cuenta. Lo vi en el navegador —
la pantalla de existencias valuaba el inventario a 0.0320, el costo que el sembrador había capturado minutos antes, y no
a 0.0400, el de la compra que acababa de confirmar. La pantalla decía la verdad; el sistema estaba mal.

Ahora es el instante de la confirmación, **topado para no pasar del final del día de recepción**. Las dos mitades
importan y cada una tiene su prueba: usar sólo la fecha sella a medianoche y pierde contra lo del día; usar sólo `now()`
haría que confirmar hoy una recepción de la semana pasada pisara un costo vigente que sí es más reciente — y la regla de
§7 es que un costo retroactivo se guarda en el historial sin cambiar el vigente.

**PREGUNTA ABIERTA:** queda sin decidir si una compra debería ganarle **siempre** a una captura manual, sin importar el
orden temporal. Hoy manda el más reciente, sea cual sea su origen. D14 define el costo vigente como «el último costo de
adquisición», lo que sugiere que una compra pesa más que una estimación a mano — pero también hay que poder corregir a
mano un costo mal recibido, y eso exige que la corrección gane. No se resolvió por su cuenta.

### D222 — Las existencias se valúan al leer, desde `Inventory`
**Estado:** Tomada · **Ámbito:** `StockController`, `ArticleStockResource`

`article_stocks` no guarda el valor a propósito (su migración lo dice): dependería del método de valuación (D152) y
guardarlo crearía una tercera fuente —además del kardex y del costo vigente— que se desviaría en silencio. Pero el
recurso tampoco lo calculaba, así que la pregunta «¿cuánto vale mi inventario?» no tenía respuesta en ninguna parte.

Se calcula en el controlador, con **una** consulta para toda la página (`ResolveArticleCost::currentForMany()`, que se
agregó en el paso 5 para el conteo físico). Y se resuelve desde `Inventory` y no con una relación en `Article` porque
`Catalog` no depende de `Costing` y no debe: la flecha va al contrario. `Inventory` sí depende de los dos (D160), así que
es el único sitio donde las dos cosas pueden juntarse.

`null` cuando el artículo no tiene costo capturado, y no cero: un cero diría que la mercancía es gratis.

---

### D223 — El 409 traía el permiso que hacía falta y el cliente lo tiraba a la basura
**Estado:** Tomada · **Ámbito:** `resources/js/api/client.js`

D170 decidió que un `RequiresAuthorizationException` responde **409 con `required_permission`** para que la interfaz sepa
qué PIN pedir sin llevar su propia tabla de «qué permiso exige cada operación». El contrato existía y estaba probado del
lado del servidor.

Y `ApiError` guardaba sólo `{type, title, status, errors}`: el campo llegaba y se descartaba. O sea que el contrato moría
un centímetro antes de servir para algo, y la primera pantalla que lo necesitara habría escrito la tabla que D170 quería
evitar.

Ahora `ApiError` conserva el cuerpo completo (`payload`) y expone `isAuthorizationRequired` y `requiredPermission`. Se
guarda el cuerpo entero y no sólo ese campo porque el siguiente contrato que agregue un dato a un 4xx —una fecha de
vencimiento, un folio en conflicto— no debería exigir tocar el cliente otra vez.

### D224 — Sin PIN sembrado, el diálogo de autorización era un callejón sin salida
**Estado:** Tomada · **Ámbito:** `comandia:demo:seed`

El negocio de demostración no ponía PIN a nadie. Y sin **ninguna** persona con PIN dado de alta, el diálogo de
autorización sólo puede responder una cosa: «código o PIN incorrectos».

Ese mensaje es el correcto —ADR-008 exige que un código inexistente y un PIN equivocado digan lo mismo, para que nadie
pueda enumerar códigos válidos— pero engaña sin querer: quien lo lee concluye que escribió mal el PIN, no que no hay a
quién pedírselo. Lo encontré así, intentando autorizar una merma con la pantalla insistiendo en que mi PIN estaba mal.

O sea que la autorización por PIN, una de las cosas que este producto vende, era justo la que no se podía demostrar.

El sembrador ahora le pone PIN al propietario, con la membresía que devuelve `provision()` y no con una consulta por
código: el código lo elige `ProvisionTenant` y buscarlo en el comando lo pondría en dos sitios.

**Y una precisión, porque mi primer diagnóstico fue falso.** Vi `employee_profiles` vacío y concluí que faltaba el perfil
de empleado. No era eso: el `pin_hash` vive en la **membresía**, y el perfil laboral es PII de contratación (§4.1, capa
3), independiente de la credencial. Lo que faltaba era el PIN, y el código de empleado ya existía. La prueba de regresión
mira la membresía por lo mismo.

### D225 — El catálogo de motivos de merma nace vacío, y la pantalla tiene que decirlo
**Estado:** Tomada · **Ámbito:** `Waste/Index.vue`

Los motivos de merma son del negocio (D27) y el alta no siembra ninguno: sembrar una lista genérica sería inventar sus
categorías de pérdida. La consecuencia es que un negocio recién dado de alta abre la pantalla de mermas, el formulario
aparece con el «Motivo» en blanco y enviar da un **422 sobre un campo que no se podía llenar**.

Ahora el estado vacío se explica y el botón de registrar queda deshabilitado hasta que exista un motivo. No se siembra
ninguno: la decisión de fondo —que los motivos los define el negocio— sigue siendo la correcta; lo que faltaba era
decirlo en pantalla en lugar de dejar que un 422 lo insinuara.

### D226 — Los slots de `DataTable` se llaman `cell:x`, y escribí `cell-x` en dos pantallas
**Estado:** Tomada · **Ámbito:** paso 11

Las dos primeras pantallas de esta tanda usaban `#cell-article` en lugar de `#cell:article`. Vue no avisa de un slot que
nadie consume, así que el componente cayó a su contenido por omisión —`{{ row[column.key] }}`— y la tabla pintó **el
objeto JSON crudo del artículo** en la celda, con la columna de captura convertida en un guion.

Es exactamente la clase de defecto que la suite no ve: el build pasa, ninguna prueba de API toca una plantilla, y el
error sólo existe en el navegador. Lo encontré al abrir la hoja de conteo y ver `{ "ulid": "01M0...", "name": ...` dentro
de una celda.

No se agrega candado, y conviene decir por qué: leer nombres de slot desde PHP exigiría parsear componentes de Vue, y el
error se ve **de inmediato** en cuanto alguien abre la pantalla. Lo que cambia es el orden de trabajo: abrir cada
pantalla nueva en el navegador antes de darla por hecha, que es lo que ya estaba escrito y esta vez funcionó.

### D227 — El candado de refs tenía un falso positivo, y la premisa que lo sostenía había caducado
**Estado:** Tomada · **Ámbito:** `FrontendRefUnwrapTest`

El candado vigilaba tres nombres —`generalError`, `fieldErrors`, `isEmpty`— con la premisa escrita de que «sólo existen
como refs de un composable en todo el proyecto, así que nombrarlos no produce falsos positivos». Dejó de ser cierta:
`ApiError` expone un getter `fieldErrors`, así que `wasteErrors.value = e.fieldErrors` es correcto y el candado lo marcó
como defecto.

Y ya pasaba antes sin que se viera: `useResourceList.js:115` hace exactamente la misma lectura, y el candado no la
detectaba porque recorre únicamente archivos `.vue`.

Ahora mira **sólo el bloque `<template>`**, y eso es lo preciso, no una concesión: el defecto que este candado cierra —un
`v-if` sobre un objeto Ref, que siempre es verdadero— **sólo puede ocurrir en la plantilla**. En el script, leer
`save.fieldErrors` sin `.value` falla ruidosamente, y este candado existe para lo que no se nota.

Verificado rompiéndolo: con `v-if="save.generalError"` en una plantilla vuelve a fallar, señalando archivo y línea.

### D228 — El rol activo NO persiste, y el selector lo presenta como si persistiera
**Estado:** ~~PREGUNTA ABIERTA~~ · **CERRADA por D234** al abrir la Iteración 4 · **Ámbito:** `ResolveTenantContext`, `ContextSwitcher.vue` (Iteración 2)

El rol activo viaja por la cabecera `X-Role` en **una sola visita**. La sucursal activa, en cambio, sí se recuerda
(`last_active_branch_id`, con su comentario en `rememberActiveBranch`). Así que al cambiar de rol en el selector, la
navegación siguiente vuelve al rol por omisión **sin avisar**.

Lo encontré verificando el conteo ciego: cambié a Almacenista, la hoja se mostró correctamente ciega, navegué al listado
y la columna de diferencias había vuelto. No era un defecto de la pantalla — era el rol que había vuelto a Propietario.

**Por qué importa más de lo que parece.** Alguien que baja deliberadamente a un rol menor —para operar con menos
privilegios, o para revisar lo que ve su equipo— cree estar operando con ese rol y opera con el mayor. La auditoría
registra el `active_role_id` real, así que el registro no miente; quien queda engañado es la persona.

**Tres caminos y ninguno es obviamente correcto:**

1. **Recordarlo como se recuerda la sucursal** (`last_active_role_id`). Coherente con lo que ya hace el contexto, y el
   selector diría la verdad. Contra: un rol elevado quedaría activo indefinidamente, y con él una sesión olvidada en una
   terminal compartida.
2. **No recordarlo, y que la UI lo diga**: el selector sería una acción momentánea («ver como…») y no un estado. Contra:
   es poco útil, porque cada navegación deshace la elección.
3. **Recordarlo con caducidad**: persiste dentro de la sesión y vuelve al rol por omisión al entrar de nuevo.

Hoy está lo peor de los dos primeros: no persiste **y** se presenta como estado. No se toca por cuenta propia porque es
una decisión del modelo de contexto de la Iteración 2 y afecta a toda la aplicación, no sólo a estas pantallas.

### D229 — Una orden de producción mostraba dos totales que se contradecían, y los dos eran correctos
**Estado:** Tomada · **Ámbito:** `Production/Show.vue`

La primera orden que completé en el navegador decía «valor de lo producido $46.20» con renglones que sumaban **$54.68**.
Una pantalla de costos que se contradice sin explicarse hace que se deje de creer en toda ella, así que valía la pena
averiguar cuál de las dos cifras estaba mal.

**Ninguna.** El valor de lo producido usa el costo vigente del producible, congelado al producir; el consumo usa el costo
vigente de cada insumo, también congelado. Y el costo de un producible se **deriva** de su receta (D16) mediante un
recosteo **asíncrono**: el jitomate había subido de 0.0320 a 0.0400 y la salsa seguía costeada con el precio viejo,
porque el trabajo de recosteo estaba en la cola sin procesar.

Así que el diseño era correcto y lo que faltaba era decir que existen dos cifras. Ahora la pantalla muestra «costo del
consumo» junto al valor de lo producido y, cuando difieren por más de un centavo, explica que la diferencia **es la señal
de que falta recostear**. Un dato que era una contradicción muda pasa a ser información útil.

Y de camino quedó claro un hecho del entorno que no es un defecto pero se comporta como uno: en desarrollo no corre
`queue:work`, así que **ningún efecto asíncrono ocurre**. Con 38 trabajos esperando en la cola, el recosteo en cascada,
el descuento de inventario del POS y las proyecciones simplemente no pasan. Cualquier verificación en el navegador que
dependa de ellos parecerá un defecto del sistema.

### D230 — Producir un artículo NO inventariable se acepta, y quizá no debería
**Estado:** PREGUNTA ABIERTA · **Ámbito:** `StoreProductionOrderRequest`, `ProductionWorkflow`

`ResolveProductionConsumption` rechaza un **componente** no inventariable con un argumento sólido: si no tiene
existencias, no hay nada que consumir. Pero el **producto de salida** sólo se valida como producible, así que se puede
producir un artículo que el sistema considera no inventariable — y la producción le da de alta existencia igual.

Lo vi produciendo «Salsa verde» del negocio de demostración, que es producible y no inventariable: la orden se creó, se
completó y escribió una entrada en el kardex de un artículo que nadie inventaría.

**El argumento para prohibirlo:** una orden de producción existe para lotes que se guardan —una salsa, un aderezo, una
masa— y lo que se guarda, se inventaría. Un platillo que se arma al momento no se «produce» con una orden: se consume al
venderse, que es otro camino (§6.2).

**El argumento para permitirlo:** obligar a marcar inventariable todo lo producible mete en el inventario cosas que
nadie quiere contar, y un negocio puede querer registrar producción de un platillo por control de merma sin llevar su
existencia.

No se resuelve por cuenta propia porque es una regla de negocio y el glosario no la fija. Mientras tanto queda como está:
se acepta, y la pantalla no lo insinúa ni lo prohíbe.

---

## Iteración 4 — decisiones de apertura

### D231 — Los eventos que cruzan módulos viven en el shared kernel, con primitivos
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `Shared/Domain/Events`, y migración de dos eventos

La regla 3 de §2 dice que el POS emite `CuentaPagada` y «los listeners de cada módulo reaccionan». La regla 2 dice que
las dependencias nunca fluyen hacia un módulo operativo. En la Iteración 3 el patrón que quedó fue que **el módulo que
escucha declara depender del que emite** (`Inventory` → `Purchasing`), y ya obligó a romper un ciclo a mano (D209).

La Iteración 4 multiplica el problema por tres: el POS emite hacia inventarios, finanzas e impresión a la vez. Si la
dirección se decide mal, el monolito modular deja de serlo justo en su punto más caliente.

**Decisión:** un evento que cruza fronteras se declara en `app/Modules/Shared/Domain/Events/` y lleva **sólo
primitivos** — ULIDs, montos como cadena, enteros. Nunca un modelo Eloquent.

Dos razones, y la segunda pesa más que la arquitectónica:

1. Nadie declara depender de un módulo operativo, así que la regla 2 se respeta tal como está escrita y el candado de
   fronteras vigila **más**, no menos: `Inventory` y `Costing` dejan de declarar `depends_on: ['Purchasing']`.
2. **Los eventos se serializan a la cola.** Pasar un modelo a un job y recargarlo al otro lado es una fuente conocida de
   bugs: el modelo pudo cambiar entre el despacho y el consumo. Con ULIDs, el oyente lee el estado que hay cuando actúa,
   o falla ruidosamente si el documento ya no existe.

Se migran los dos que **cruzan de verdad**: `PurchaseReceiptConfirmed` (lo escuchan `Inventory` y `Costing`) y
`ArticleCostChanged` (lo escucha `Catalog`). Los que no cruzan se quedan donde están: `StockMovementRecorded`,
`RecipeChanged` y `TenantProvisioned` son internos de su módulo o del kernel, y moverlos sería ceremonia.

**Alternativas descartadas.** Seguir el patrón actual era gratis hoy y dejaba el grafo con flechas hacia arriba en el
punto más caliente. Corregir la regla 2 con una ADR nueva era honesto con lo ya construido, pero deja la regla más
débil y el candado vigilando menos — se paga en cada iteración siguiente.

### D232 — El diario financiero mínimo y los métodos de pago se adelantan a la Iteración 4
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** hoja de ruta §14, módulo `Finance`

§6.3 define el corte de caja como «esperado **del diario** vs declarado, por método», y la hoja de ruta pone el diario
en la Iteración 5. O sea que el alcance de la 4, tal como estaba escrito, **no cerraba sobre sí mismo**: o el corte no
existía, o salía de una fuente paralela — que es exactamente lo que §6.5 prohíbe y lo que ADR-004 existe para evitar.

**Decisión:** entran en la Iteración 4, dentro del módulo `Finance`:

- `financial_movements`: el diario inmutable tipado con documento origen (ADR-004).
- `payment_methods`: el catálogo con la bandera «afecta cajón», que los pagos multi-línea necesitan igual.

Se escribió aquí que la Iteración 5 conservaría gastos, retiro→depósito, crédito a clientes, liquidación de propinas y
los cortes ricos. **D235 lo dejó sin efecto** horas después: al ampliar el POS a «completo», cuatro de esas cinco
cosas resultaron necesarias para que el arqueo cuadre, y la Iteración 5 se absorbió. Lo que sigue en pie de esta
decisión es su parte sustantiva —el diario y los métodos de pago entran en la 4— y el argumento que la sostiene.

Conviene dejar dicho por qué la partición que propuse aquí no aguantó: la hice mirando **tablas**, y la línea correcta
era **el arqueo**. Un corte que no conoce los gastos desde caja ni las propinas liquidadas no cuadra nunca, y eso no se
ve contando tablas — se ve escribiendo la fórmula del esperado, que es lo que hizo falta para descubrirlo.

**Alternativas descartadas en su momento.** Cerrar la sesión sin corte dejaba un POS que no se puede poner en manos de
un cajero, y salir a operación real temprano es D1. Fusionar las iteraciones 4 y 5 parecía duplicar la entrega más
grande del proyecto — y acabó siendo lo que D235 decidió, con el riesgo mitigado por tandas en lugar de por alcance.

### D233 — La propina es del titular de la cuenta y se congela en la línea de pago
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `Pos`

§6.3 dice «propina por línea de pago, asociada al mesero de la cuenta», y no dice qué pasa cuando la cuenta se divide,
se junta con otra o le mueven items — que es cuando el dinero cambia de dueño.

**Decisión:** la cuenta tiene un **mesero titular** (`pos_accounts.waiter_membership_id`, NOT NULL) y cada línea de pago
**congela** en `pos_payments.tip_membership_id` a quién se le atribuye su propina, **en el momento del cobro**.

Lo que eso compra: **una operación posterior no reescribe propinas ya pagadas**. Si a las 22:00 se juntan dos cuentas,
las propinas cobradas a las 21:00 siguen siendo de quien las ganó. Sin congelarlas, juntar cuentas movería dinero de una
persona a otra sin que nadie lo hubiera decidido — y la liquidación de la Iteración 5 pagaría esa cifra.

Al **dividir**, cada subcuenta hereda el titular de la original. Al **juntar**, manda el titular de la cuenta destino y
el cambio queda escrito en `pos_account_operations`.

**Alternativas descartadas.** Atribuirla a quien cobra es lo que hacen los sistemas de barra y es más simple, pero en un
restaurante con caja central todas las propinas del turno acabarían a nombre del cajero. Repartirla proporcionalmente
entre los meseros que capturaron es más justo cuando dos atienden la misma mesa, y cuesta mucha más maquinaria —un
reparto con redondeo que debe sumar exacto, recalculado cada vez que se mueve un item— que la liquidación de la
Iteración 5 heredaría entera.

### D234 — El rol activo se recuerda, y se reinicia al iniciar sesión
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** `ResolveTenantContext`, `tenant_memberships`

Cierra la pregunta abierta de D228. El rol activo viajaba por la cabecera `X-Role` en una sola visita, mientras la
sucursal sí se recordaba, así que el selector presentaba como estado algo que se deshacía en la navegación siguiente.

**Decisión:** columna `tenant_memberships.last_active_role_id` (FK `roles`, SET NULL), escrita por
`ResolveTenantContext` cuando el rol cambia — exactamente como ya se escribe `last_active_branch_id`. Y **se reinicia al
iniciar sesión**: el rol por omisión gana al autenticarse.

El reinicio es la mitad que importa. Recordarlo indefinidamente era el camino más simple y dejaba un rol elevado activo
para siempre en una terminal compartida del POS, que es el escenario normal de una caja. Reiniciar al entrar mantiene el
selector honesto durante la jornada y cierra la puerta al final de ella.

Va como **paso 0** de la Iteración 4, antes de cualquier tabla: los descuentos, la cancelación post-comanda y el cajón
de dinero dependen todos de saber con qué rol opera una persona.

### D235 — El POS entra completo, y eso reescribe la hoja de ruta
**Estado:** Tomada (decisión del dueño del producto) · **Ámbito:** hoja de ruta §14, iteraciones 4 a 7

El alcance original de la Iteración 4 era «POS núcleo». La instrucción fue que el POS vaya **lo más completo**
posible, y que entre lo que necesite de otras iteraciones.

Para decidir qué necesita de verdad recorrí §6.3 renglón por renglón preguntando «¿esto se puede operar sin
aquello?». El criterio que salió, y que es el que separa esta decisión de una ampliación indiscriminada: **lo que
impide operar entra; lo que mejora la operación, no.**

**Cuatro cosas resultaron necesarias:**

1. **Mesas.** El arquetipo primario es «restaurante con mesas, meseros y cuentas abiertas» (§1). Un POS que no sabe
   en qué mesa está una cuenta no sirve para el negocio para el que se diseñó, y §6.3 exige liberar la mesa cuando
   todas las sub-cuentas están pagadas.
2. **Gastos desde caja.** El cajero paga los garrafones con dinero de la caja. Un arqueo que no los conoce **no
   cuadra nunca**, y una diferencia que siempre existe deja de ser una señal.
3. **Crédito a clientes.** §6.3 prohíbe la «cuenta que nunca se cierra», y el crédito **es** el mecanismo para el
   fiado. Sin él, un negocio que da crédito deja cuentas abiertas para siempre — justo lo prohibido.
4. **Liquidación de propinas.** Esta iteración crea las propinas; sin liquidarlas, el dinero se acumula en la caja
   sin salida registrada y el arqueo se descuadra al entregarlas a mano.

La fórmula del corte lo demuestra en una línea: sin gastos desde caja, sin liquidación de propinas y sin abonos de
crédito, el «esperado» es sistemáticamente falso.

**Y una que no venía de ninguna iteración: las impresoras.** Las áreas de preparación y las terminales no tenían a
dónde imprimir. Sin eso, «ruteo por área» no tiene destino y el cajón de dinero —que se abre por la impresora— no
se puede abrir. Era un hueco del diseño, no un recorte.

**Lo que se quedó fuera, y por qué se sostiene:**

- **Promociones** (D50): no impiden operar. Los descuentos manuales cubren el caso, y meter un motor de promociones
  antes de que el cálculo de la cuenta esté probado es el orden equivocado.
- **Editor visual de planos y piso en vivo**: es superficie con su propia ADR (ADR-003) y su propia infraestructura
  de tiempo real. El POS opera sabiendo la mesa; dibujarla es otra cosa. Las mesas se dan de alta por formulario.
- **CFDI, perfiles fiscales, direcciones, historial rico del cliente**: el ticket final ya folia y ése será el folio
  facturable (ADR-005).

**Consecuencia de plan, que hay que decir en voz alta.** Con este alcance la Iteración 5 (Finanzas) se queda **casi
sin contenido propio** —le restaban caja chica y conciliación, ya declaradas como evoluciones—, así que se absorbe.
La hoja de ruta pasa de once iteraciones a **diez**: la 6 se queda con el editor visual y el tiempo real, y la 7 con
promociones y el expediente fiscal del cliente.

**El riesgo, asumido y mitigado.** Es la iteración más grande del proyecto, más o menos el doble de la 3:
veintiocho tablas y veinte pasos. Se entrega en **tres tandas** —cimientos, vender y cobrar, que el dinero cuadre—
cada una con su verificación en navegador y su commit. Las tandas son también los cortes naturales si en algún
punto conviene cerrar lo entregado y seguir después.

### D236 — El paso 1 establece el contrato de eventos y NO migra el de la recepción de compra
**Estado:** Tomada · **Ámbito:** `Shared/Domain/Events`, `CrossModuleEventsTest` · **Corrige el §7.3 del diseño de la Iteración 4**

El diseño aprobado decía que se migraban **dos** eventos al kernel: `PurchaseReceiptConfirmed` y `ArticleCostChanged`.
Al implementar, el mapa real de oyentes registrados dijo otra cosa.

**Primero: `ArticleCostChanged` no cruza módulos.** Lo emite `Costing` y lo escucha `Costing`. Su propio comentario
afirmaba que «en la Iteración 3 lo escucha inventarios para valuar movimientos al costo vigente», y era **falso**:
`Inventory` resuelve el costo llamando a `ResolveArticleCost` cuando registra un movimiento, no reaccionando al evento.
Yo mismo lo di por bueno al escribir el diseño. Un comentario que afirma un acoplamiento inexistente hace que alguien
diseñe alrededor de él, así que quedó corregido en el archivo.

O sea que sólo hay **un** evento que cruza módulos de dominio hoy, no dos.

**Y segundo: migrarlo cambiaría un enlace atómico por uno reparable.** El oyente de `Inventory` escribe el enlace de
vuelta —`movement_id` y `lot_id` de cada línea del documento— **dentro de la misma transacción** que crea el movimiento
del kardex. Ese enlace es lo que hace detectable una confirmación a medias (`was_applied` por línea), y existe como
respuesta a uno de los cinco defectos de D220: un fallo de oyente que hacía **mentir** a la confirmación.

Para que `Purchasing` escribiera su propia tabla tendría que escuchar a `StockMovementRecorded`, y ese evento se emite
**fuera** de la transacción a propósito, con su razón escrita: quien escuche no debe poder abortar la escritura del
kardex. La inversión exigiría además una herramienta de reparación para un estado incompleto que hoy no puede ocurrir.

**Lo que sí entrega el paso 1**, que es donde está el valor para esta iteración:

1. `App\Modules\Shared\Domain\Events` con la interfaz `CrossModuleEvent`, que obliga a llevar el `tenantId` — lo que
   permite a un oyente abrir el contexto de negocio cuando corre en una cola, sin sesión ni petición.
2. La convención escrita en la regla 3 de §2 de la Arquitectura Maestra, no sólo en un candado.
3. **El candado**, con tres comprobaciones: un evento con oyentes en otro módulo vive en el kernel; ningún evento del
   kernel importa un modelo Eloquent; y todo evento del kernel implementa el contrato. Lee los oyentes **registrados**
   del despachador y no el texto de los proveedores, porque aquí busca presencias y una expresión regular daría falsos
   negativos con un registro condicional.
4. `PurchaseReceiptConfirmed` como **excepción declarada**, con su motivo y su plan escritos en el propio candado — el
   patrón que el proyecto ya usa para `withoutGlobalScopes`.

**El plan de su migración**, para que no se pierda: migra cuando el enlace se **derive** del kardex en lugar de
guardarse. `stock_movements` ya apunta al documento origen; lo único que falta es saber la línea. Ese cambio toca una
tabla inmutable, así que se hace con su propio diseño y no de pasada.

**Y el candado se verificó por ruptura, por los dos lados:** quitando la excepción declarada falla la primera
comprobación; con un evento del kernel que lleva un modelo y no implementa el contrato fallan las otras dos.

Es un recorte de mi propio plan, no del alcance de la iteración: los seis eventos del POS nacen en el kernel con el
contrato puesto, que era el objetivo de D231.

---

### D237 — El IVA se extrae redondeando, no truncando, y hay candado nuevo

**Contexto.** Los precios son IVA incluido (D30), así que el impuesto de una línea se **extrae**:
`total − total ÷ (1 + tasa/100)`. Lo implementé con `bcsub($total, $base, 2)` y una prueba que esperaba `'6.21'` para
$45.00 al 16 % recibió `'6.20'`.

**El problema.** `bcmath` trunca; no redondea. $45.00 al 16 % contiene 6.206897 de impuesto, y bajar de escala 6 a escala
2 con `bcsub` a secas se queda con 6.20. Un centavo por renglón, **siempre hacia abajo**, en el número que el ticket
desglosa y con el que el corte tiene que cuadrar. Nada falla: sale una cifra plausible que no corresponde a lo que el
cliente pagó.

Lo que lo hace anotable es que ya estaba escrito. El encabezado de `Decimal` lo explica desde la Iteración 2 —«`bcmath`
**trunca** en lugar de redondear… truncar sistemáticamente sesga los costos hacia abajo»— y el resto del código lo
respeta sin una sola excepción: cada reducción de escala del repositorio pasa por `Decimal::round(bcmul(..., 6), 2)`. La
convención era correcta y vivía sólo en el hábito, así que la primera vez que escribí la operación en un módulo nuevo la
rompí.

**Decisión.** `PosOrderItem::vatAmount()` divide con escala 6 y cierra con `Decimal::round(..., 2)`. Y se agrega el
candado `tests/Architecture/MoneyRoundingTest.php`.

**Qué comprueba el candado, y qué no.** `bcadd($a, $b, 2)` entre dos importes de dos decimales es exacto: no hay nada que
truncar, y prohibirlo sería ruido. El truncamiento aparece cuando un operando trae más decimales que la escala destino, y
saber eso en general exige seguir el dato. Lo decidible es la **cascada dentro de una función** —una variable calculada
con escala alta y usada después con escala 2— que es exactamente la forma del defecto. Más la prohibición de `bcdiv` a
escala de dinero, donde el residuo se pierde por definición.

Queda fuera el operando que llega con decimales desde la base o desde un parámetro. El candado no lo disimula: cierra la
forma que ya se cometió dos veces.

**Escala 0 sí se permite, y no por conveniencia.** `bcdiv($amount, $multiple, 0)` en `RoundingMode::ceilToMultiple` es el
cociente entero de un techo a múltiplos: ahí truncar **es** la operación pedida. La frontera está en la intención que
declara la escala: 0 dice «quiero la parte entera»; 1 o 2 dice «quiero dinero», y ahí truncar nunca es lo que se quiso.
Fue el propio candado quien me obligó a formularlo — su primera versión acusaba a esa línea, que está bien.

**Y una lección sobre cómo se escriben estos candados.** La primera versión usaba
`/bcdiv\s*\([^;]*,\s*([0-2])\s*\)/` y acusaba a `PurchaseReceiptWorkflow`, que hace lo correcto: `[^;]*` cruza los
paréntesis de cierre, así que en `Decimal::round(bcmul($a, bcdiv($b, '100', 6), 6), 2)` el patrón arrancaba en el
`bcdiv` y hacía coincidir el `, 2)` del redondeo de afuera. Los paréntesis no se equilibran con expresiones regulares.
Se cambió por un recorrido de caracteres contando profundidad —quince líneas— y desapareció la clase entera de falso
positivo. Es el tercer candado de esta iteración cuya primera versión marcaba código correcto.

---

### D238 — El candado optimista de la cuenta es opcional en la petición

**Contexto.** Una cuenta la tocan varias personas a la vez: el mesero agrega, el cajero cobra, otro mesero mueve un item.
`pos_accounts` lleva una columna `version` que `CaptureOrderItems::recalculate()` incrementa en SQL, y quien opera manda
la versión que leyó; si no coincide, la cuenta cambió mientras la tenía en pantalla y recibe 409.

**La decisión.** Mandar `version` es **opcional**. Un cliente que no la manda escribe sin comprobación y acepta el
riesgo; la pantalla del POS la manda siempre.

**Por qué, y qué deuda genera.** Exigirla rompería cualquier integración que todavía no la conozca —la API es ciudadano
de primera clase y la app de Flutter no existe aún—, y un 409 obligatorio en el primer intento de todo cliente nuevo es
una barrera de entrada por una protección que ese cliente puede no necesitar. La deuda es real y está identificada: un
integrador que nunca la mande puede sobrescribir lo que no vio, y el sistema no se lo dirá. Si aparece un caso, se
endurece por versión de API, no cambiando el comportamiento de la actual.

La alternativa que descarté fue **derivar** la versión de `updated_at`. Sería una columna menos, y no funciona: los items
cuelgan de la cuenta y un cambio en un item que no toque la fila de la cuenta dejaría la marca de tiempo quieta. La
columna se incrementa explícitamente en el recálculo, que es el único punto por donde pasa todo cambio de importes.

---

### D239 — El estado de una mesa lo mueve `Floor`, no `Pos`, y no por evento

**Cómo apareció.** El paso 7 abría la cuenta con `$table->update(['status' => Occupied])` desde
`Pos\Application\AccountWorkflow`, y el comentario que yo mismo había escrito ahí decía: «No es el POS escribiendo en el
salón por gusto: el estado de una mesa lo mueve lo que pasa con sus cuentas, y `Pos` es el dueño de ese hecho». El
candado de fronteras (`ModuleBoundariesTest`) falló pidiendo que `Pos → Floor` se declarara, y al ir a declararla releí
el comentario. Era una racionalización.

**Por qué era falso.** Si `Pos` fuera el dueño del estado de una mesa, la columna viviría en `Pos`. No vive ahí: la
pantalla de piso la lee, `JoinTables` manipula mesas, `TableInvariantException` es de `Floor`, y `isAvailable()` combina
el estado con «¿está unida?». Un `update()` desde fuera salta todas esas invariantes.

Y había ido más lejos de lo que yo creía: `releaseTableIfEmpty` leía el ajuste **`floor.use_cleaning_state`** y borraba
`joined_to_table_id` de las mesas unidas. Eso ya no es acoplamiento, es un módulo implementando las reglas de otro. El
día que `Floor` agregue una —reservaciones (D33), o que un grupo unido no se libere por partes— `Pos` no se enteraría.

**La decisión.** Nace `Floor\Application\TableOccupancy` con `occupy()`, `markBillRequested()` y `release()`. `Pos`
conserva la única pregunta que le corresponde —«¿queda alguna cuenta viva en esta mesa?», que es una pregunta sobre
cuentas— y delega el significado de ocupar y liberar. La dependencia queda declarada en `config/comandia.php`.

**Por qué NO es un evento, que era la respuesta tentadora.** La regla 3 de §2 manda los efectos entre módulos por evento
de dominio, y aquí sería un error: la ocupación tiene que ser inmediata y en la misma transacción, porque la
comprobación de «no se abre una segunda cuenta en una mesa ocupada» lee justo ese estado. Con consistencia eventual, dos
meseros sientan a dos grupos en la mesa 4 y el sistema los deja.

El criterio, entonces, no es «cruza una frontera → evento». Es **si el efecto puede llegar tarde**: el descuento de
inventario y el asiento del diario sí pueden y van por evento; la ocupación de una mesa no. Lo que la frontera exige es
que el acoplamiento sea explícito y estrecho — `Pos` depende del **servicio**, que es superficie pública del módulo
(§2), y no del modelo Eloquent.

**`Floor` es de la misma capa que `Pos`, y eso no es una excepción.** La capa ordena de qué se puede depender hacia
abajo; no prohíbe que dos superficies operativas se conozcan. Lo que la regla exige es que la dependencia esté declarada
y vaya en un solo sentido, y `Floor` no depende de `Pos`.

**Y de paso destapó un hueco.** §6.4 pinta «cuenta solicitada» por mesa en la vista de piso, y hasta el paso 7 **nada
escribía ese estado**: el enum lo tenía, la pantalla lo sabía dibujar, y ninguna transición llegaba a él. Es la señal de
que a esa mesa le falta cobrar y no volver a atenderla. `markBillRequested()` lo cierra, con su prueba. Un estado que
existe en el enum y que ninguna transición alcanza es código muerto que parece vivo — la misma familia que el candado de
oyentes registrados vigila en los eventos, y aquí no hay candado que lo vea.

---

### D240 — El ruteo a áreas es por SUCURSAL, con tabla propia en `Pos`

**El hueco.** El paso 8 es «comandar: ruteo por área», y ni la Especificación ni el diseño dicen **de dónde sale el área
de un artículo**. Sin eso, «ruteo por área» no tiene de dónde rutear. Es una decisión de diseño que los documentos no
cubren; la tomo aquí con su alternativa y su razón, en lugar de detener el paso.

**Por qué NO una columna en `articles`**, que era lo obvio: las áreas son **por sucursal** — `preparation_areas` es
única por `(tenant, branch, code)` y cada una tiene su propio almacén. Un artículo es del negocio entero. Una columna
`articles.preparation_area_id` apuntaría a la cocina de **una** sucursal, y en un negocio con dos locales las comandas
del segundo saldrían por la impresora del primero. No es una objeción teórica: la primera cadena de dos sucursales lo
rompe el primer día, y el síntoma sería «la cocina no recibe nada» sin nada que falle.

Tampoco una columna en `article_categories`, por lo mismo.

**Por qué la tabla vive en `Pos` y no en `Organization`**, que sería el sitio natural: `Organization` es **kernel**, y el
kernel no depende de ningún módulo de dominio (§2, regla 1). Una FK a `article_categories` desde ahí invertiría la
flecha. `Pos` ya depende de `Catalog` y de `Organization`, y el ruteo es una decisión del punto de venta: a dónde se
manda a preparar lo que se vende.

**La resolución, en orden:** regla del **artículo** en esa sucursal (el override: «las hamburguesas van a la parrilla»);
si no, la de su **categoría**, ascendiendo un nivel al padre; si no, **nada**. El ascenso es lo que hace la carga de
datos soportable: «Bebidas → barra» son dos toques, no cuatrocientos. Y `null` es legítimo, no un caso de error: un item
sin área no se comanda, porque una cerveza que el mesero saca de la nevera no necesita que nadie prepare nada.

**Se resuelve al CAPTURAR y se guarda en la línea**, no al comandar. Si se resolviera al comandar, cambiar una regla a
media tarde partiría una cuenta abierta entre dos áreas: el mismo plato iría a la cocina si se capturó antes y a la
parrilla si se capturó después. Es la misma razón por la que el precio se congela, y tiene la misma consecuencia
deseable: borrar una regla no reescribe ninguna comanda ya emitida.

**Permiso: `organization.preparation_areas.manage`, sin inventar uno nuevo.** Configurar qué va a la cocina y qué a la
barra *es* configurar las áreas. Y un permiso nuevo no existiría para los negocios que ya corren, así que su ruta
respondería 403 para todo el mundo hasta que alguien acordara correr `comandia:permissions:sync` (D219). Reusar uno
existente evita ese hoyo sin inventar nada.

**El caché del resolutor es `scoped`, no `singleton`.** Una orden de doce líneas consultaría la tabla veinticuatro veces
sin memoria. Un singleton la conservaría entre peticiones —y entre **tenants** en el mismo proceso de Octane—, mandando
comandas a la impresora de otro negocio sin que nada fallara. Es el peor tipo de error: silencioso y plausible.

---

### D241 — Una comanda por ÁREA, y comandar es idempotente

**Una por área, no una por orden.** Una orden puede tocar cocina, barra y postres; cada área recibe su propio papel en su
propia impresora. Una comanda con las tres cosas obligaría a la cocina a leer la barra y a la barra a leer los postres, y
en hora pico eso es un plato olvidado. De ahí que una cuenta de tres órdenes pueda tener hasta nueve comandas, como dice
el diseño. El evento `PosOrderCommanded` se emite también **por área**: uno por orden obligaría a quien imprime a volver
a agrupar lo que el POS ya agrupó.

**Comandar sólo toma los items en `captured`.** Volver a comandar una orden ya comandada no reimprime nada ni duplica
comandas: no hay items que tomar, y la respuesta es una lista vacía sin fingir un error. Importa porque la red de un
restaurante se cae y el mesero vuelve a picar el botón cuando no ve confirmación — que es exactamente el momento en que
un sistema mal hecho manda la comida dos veces. Para sacar otra vez el mismo papel existe la reimpresión, que es otra
acción, cuenta las veces y queda auditada.

**Los items sin área también pasan a «comandado», y no producen papel.** El hecho que marca «comandado» no es «la cocina
lo recibió», es «esto ya salió y el cliente lo tiene». Dejar la cerveza en «capturado» para siempre haría que quitarla de
la cuenta fuera un borrado sin rastro cada vez, y lo que pasó es que alguien se llevó una cerveza.

**Comandar mueve la `version` de la cuenta** aunque no cambie ni un importe. Cambia lo que la cuenta **contiene**, y
quien la tenía en pantalla desde antes ya no está mirando lo mismo: sin el empujón podría cobrar creyendo que nada se
comandó, y el candado optimista no lo detendría porque la versión coincidiría.

---

### D242 — La frontera del PIN al cancelar es «comandado», no el monto

**Un solo endpoint, dos comportamientos.** Desde fuera es la misma intención —«quita esto»— y lo que cambia es lo que el
sistema tiene que exigir, que lo decide el estado de cada item y no el cliente. Dos endpoints obligarían a la pantalla a
llevar su propia copia de la frontera, y una pantalla desactualizada mandaría al equivocado.

- **No comandado → se borra.** Sin motivo, sin PIN, sin rastro en la cuenta: no ocurrió nada. Pedir PIN aquí sería
  burocracia por un plato que el mesero picó mal y corrigió en dos segundos, y entrenaría a la gente a tener el PIN a
  mano — que es como un PIN deja de proteger.
- **Comandado → se registra.** Motivo obligatorio, PIN de un superior, comanda de cancelación al área, y hay que decir
  qué se hizo con la comida.

**Sin umbral, a diferencia de las mermas (D27, D170).** Lo que se protege no es el valor: es que **alguien ya trabajó**.
Un plato de 40 pesos que la cocina hizo y que desaparece de la cuenta es la vía más común de robo en un restaurante, y es
por lo que §6.3 lo pone en la lista de acciones sensibles.

**El destino lo decide el humano.** Podría inferirse del estado —«si está servido, es merma»— y sería adivinar: un plato
marcado servido puede no haberse tocado, y uno en «preparando» puede llevar media hora en la plancha. De ese dato depende
que el inventario registre una merma o devuelva el producto, así que adivinarlo movería existencias a ciegas. El sistema
sugiere, el humano decide — la misma regla que con los precios.

**Se audita en el SERVICIO y no en el controlador**, a diferencia del resto del módulo: sólo el servicio sabe qué se
borró y qué se canceló, y son dos acciones auditables distintas en una sola petición. Se registran el autorizador **y**
quien pidió la cancelación: saber quién la pide es la mitad del patrón que el reporte de robo hormiga busca.

**Un borrado también se audita**, aunque §6.3 diga que «no hay rastro». Lo que no queda es rastro **en la cuenta** —la
línea desaparece, y eso es correcto porque no se cobró nada—. Pero que alguien borre veinte líneas en un turno es un
patrón que el negocio querrá ver, y sin el asiento no habría dónde verlo.

---

### D243 — `actingAsSpa` tira la sesión, y varias pruebas de autorización eran más débiles de lo que parecían

**El síntoma.** Autenticarse como un segundo usuario dentro de una misma prueba respondía **401 «No has iniciado
sesión»**, con la membresía activa, el rol puesto y todo en orden.

**La causa.** `withSession()` **mezcla** en la sesión que el cliente de pruebas ya traía, y la de la petición anterior
sigue ahí con la llave de autenticación del usuario anterior. El guard de sesión resolvía la sesión vieja y fallaba.

Es la misma familia que el `flushHeaders()` del paso 0 de esta iteración: estado del cliente de pruebas que sobrevive de
una petición a la siguiente y hace que la prueba mida otra cosa de la que cree. Van dos en una iteración.

**Lo caro no era el 401.** Como «autentícate como otro» no funcionaba, la costumbre establecida era preparar al usuario
ajeno **por modelos** y no ejercitar nunca su camino HTTP — que es justo el camino que una prueba de autorización o de
aislamiento existe para vigilar. Una limitación del ayudante estaba moldeando cómo se escribían las pruebas, y en la
dirección de hacerlas más débiles sin que se notara.

**La decisión.** `actingAsSpa()` empieza con `flushSession()`. El arreglo va en el ayudante y no en cada prueba.

---

### D244 — Un agente de impresión no es un usuario, y por eso tiene su propia autenticación

**La decisión.** `print_agents` con token propio y `AuthenticatePrintAgent` como middleware, en lugar de colgarle una
membresía y reusar Sanctum.

**Lo cómodo habría sido lo contrario.** El mecanismo de usuarios ya existe, ya resuelve tenant y permisos, y habría
pasado todos los candados sin decir palabra. Y le abriría la API entera a un proceso que corre sin vigilancia en una
computadora de cocina que cualquiera puede tocar: un token robado de ahí podría consultar ventas, cambiar precios o
cancelar cuentas.

Con un agente, un token robado sólo puede pedir e imprimir los trabajos de **su** sucursal. No hay parámetro por el que
pedir lo ajeno: el tenant y la sucursal salen de la fila del agente, nunca de la petición (ADR-002).

**Aparece en dos listas de excepciones, y eso es preferible a no aparecer.** En `RoutePermissionTest` —porque un agente
no tiene rol activo y por tanto no puede tener un permiso del rol activo, que es lo único que ese candado sabe
comprobar— y en `AuthorizationDisciplineTest`, porque resolver «¿de quién es este token?» ocurre antes de que exista
contexto, igual que el selector de tenant del login. Las dos excepciones están declaradas con su razón. Colgarle una
membresía habría evitado las dos listas y habría sido el error.

**El token se guarda hasheado y se muestra una vez.** SHA-256 sin sal, a propósito: hace falta buscarlo por índice único
en cada sondeo de cada agente, cada pocos segundos, y un recorrido de tabla con `password_verify` no lo aguanta. La
entropía de 40 caracteres aleatorios hace el trabajo que en una contraseña hace la sal, y lo que importa —que un volcado
de la base no entregue tokens usables— se cumple igual.

**Permiso: el de las impresoras**, sin inventar uno nuevo (D219). Un agente es la contraparte de una impresora en la
misma infraestructura. La tensión honesta es que el token es una **credencial**, así que quien puede cambiar la IP de una
impresora también puede emitir una; en un negocio de comida son la misma persona, y separarlo después es agregar un
permiso, no rehacer nada.

---

### D245 — Reclamar es exclusivo, reportar es idempotente, y un fallo NO se reintenta solo

**Reclamar con lock, en lote.** Puede haber varios agentes en la misma sucursal —una tableta y la computadora de la
caja— y sin exclusión los dos leerían los mismos pendientes: la cocina recibiría cada comanda dos veces. El
`lockForUpdate()` es el mismo mecanismo del asignador de folios y por la misma razón. En lote porque una mesa de ocho
produce tres comandas a la vez, y de una en una serían tres viajes desde la cocina con el papel saliendo a destiempo.
El orden es por `id` y no por `created_at`: dos trabajos del mismo segundo quedarían a merced de MySQL.

**Reportar es idempotente.** El agente vive en una computadora con una red que se cae: reporta «impreso», no recibe
respuesta y vuelve a reportar. La segunda vez devuelve lo mismo sin contar otro intento. Sin esto, el agente tendría que
llevar su propio registro de qué reportó — pedirle memoria a la parte menos confiable del sistema.

**Y sólo puede reportar lo que reclamó.** Con dos agentes, el segundo podría marcar como impreso el papel del primero:
la cocina no recibiría nada y el sistema diría que sí.

**Un fallo no se reintenta solo.** Un fallo de impresión casi nunca es transitorio: se acabó el papel, la impresora está
apagada, la IP cambió. Reintentar automáticamente daría veinte intentos en un minuto y, cuando alguien pusiera papel,
veinte comandas saliendo juntas — con platos repetidos que la cocina no puede distinguir. El trabajo queda en `failed`
con su motivo, visible, y una persona decide. Es el mismo criterio que el POS aplica al inventario: preferir que un
humano vea el problema antes que un automatismo lo multiplique.

**`attempts` no se reinicia al reintentar**: es la cuenta de cuántas veces se ha intentado sacar este papel, y
reiniciarla borraría justo la señal de que algo lleva rato sin salir.

---

### D246 — Un área sin impresora NO tumba la venta

**La decisión.** Si el área de un item no tiene impresora asignada, no se encola nada y comandar sigue adelante.

**Va contra el instinto.** Lo natural sería fallar y avisar, y fallar aquí **impediría vender** por una configuración de
impresoras incompleta — exactamente lo que §6.2 prohíbe cuando dice que el POS nunca se bloquea. Todo el oyente de
impresión va envuelto por lo mismo: es la lección de D220 puesta desde el diseño y no después.

**La contrapartida es real y hay que decirla:** una cocina puede quedarse sin recibir papeles y nadie se entera en el
momento. Lo cubre la pantalla de trabajos —que muestra qué se encoló, qué falló y con qué error— y el `last_seen_at` del
agente, que contesta la pregunta que de verdad se hace una cocina: no «¿falló el trabajo?», sino «¿está vivo el agente?».
Sin esa columna, un agente apagado y uno sin trabajos se ven idénticos.

Lo que **no** lo cubre es un error en la cara del cajero, y esa elección es deliberada.

---

### D247 — El payload de impresión es JSON, son datos y se congela

**La excepción autorizada.** CLAUDE.md prohíbe JSON en datos de dominio y nombra dos excepciones: la bitácora y los
payloads de impresión. Es legítima y no una comodidad: un payload **no se consulta** —no se filtra por él, no se agrega,
no se une con nada—, se escribe una vez y se lee entero. Y su forma depende del tipo de documento: una comanda, un ticket
final y una apertura de cajón no comparten ni una columna, así que normalizarlo daría tres tablas de detalle que nadie
consultaría por separado.

**Son DATOS, no texto ya formateado.** Formatear en el servidor ataría el papel al ancho —una comanda armada para 80 mm
sale ilegible en una de 58— y el ancho es de la impresora, no del documento. Y el puente Flutter y el agente de Windows
son dos implementaciones: si el servidor mandara ESC/POS, cada una tendría que deshacerlo.

**Se congela al encolar**, con los nombres que ya venían congelados en la línea (D28). Reimprimir vuelve a mandar el
mismo papel un mes después, aunque el artículo se haya renombrado. Una reimpresión que dice algo distinto del original es
lo único que una reimpresión no puede hacer.

**Lleva `version` desde el primer trabajo.** El agente vive en máquinas que se actualizan tarde: cuando el payload
cambie, un agente viejo tiene que poder decir «no entiendo esto» en lugar de imprimir basura.

---

### D248 — Abrir el cajón es un trabajo de impresión, y exige PIN

**Es un trabajo de impresión y no un truco:** el cajón no tiene cable propio, se abre mandándole una secuencia a la
impresora de tickets. Modelarlo como otra cosa obligaría a un segundo canal hacia el mismo agente y la misma impresora.
De ahí `kind = drawer_open` con `pos_ticket_id` nulo, y un CHECK que ata las dos cosas.

**Exige PIN, sin umbral.** Abrir el cajón fuera de un cobro es la forma más directa de sacar dinero sin que aparezca en
ningún lado: no hay documento, no hay venta, no hay nada que conciliar después. CLAUDE.md y §6.3 lo ponen en la lista de
acciones sensibles, y no hay «montos pequeños» que lo eximan porque no hay monto en absoluto. El motivo también es
obligatorio: un cajón abierto sin motivo a las tres de la mañana no se puede explicar en el corte.

Lo que el PIN compra no es impedirlo —un gerente puede abrirlo cuando haga falta— es que quede registrado **quién lo
autorizó**, con nombre, hora y motivo.

---

### D249 — Entra el CONTRATO del agente y un cliente que lo ejercita; no entra el ejecutable

**Lo que entra:** las tres rutas (`jobs/next`, `printed`, `failed`), la autenticación por token y el formato del
payload. **Lo que no:** el instalador, el descubrimiento de impresoras, el servicio de Windows.

**Por qué el contrato no puede esperar:** es lo que las dos implementaciones previstas —el puente Flutter de v1 y el
agente de escritorio— tienen que compartir, y cambiarlo después significa actualizar software instalado en computadoras
de cocina. Es el cambio que nadie quiere hacer.

**Y por eso hay un cliente de prueba** (`comandia:print:agent`), que lo consume de punta a punta e imprime a archivo. Un
contrato escrito y no ejercitado es un contrato que no existe: si algo es incómodo o falta, se descubre aquí y no dentro
de seis meses en una cocina. Imprime a `.txt` porque no hay hardware en la máquina de desarrollo —`ENTORNO_LOCAL.md` §7
ya lo dice— y lo que sí queda verificado es todo lo demás, que es donde están los errores caros.

Su formateo es **de mentira a propósito**: pinta el payload de forma legible y no pretende ser el rendido final. Meter
ahí el ancho de papel, los cortes y el juego de caracteres daría la ilusión de que el rendido ya está resuelto.

---

### D250 — `actingAsPrintAgent()`: un agente no habla como un navegador

**El síntoma.** Una petición con un token de agente perfectamente válido respondía **401 «No has iniciado sesión»**, pero
sólo cuando antes de ella había habido un `actingAsSpa` en la misma prueba.

**La causa, tres capas antes de donde parecía.** El cliente de pruebas arrastra las cabeceras de la petición anterior, y
el `Referer` que `actingAsSpa` pone es justo lo que hace que Sanctum considere la petición «stateful» y meta
`AuthenticateSession` en la cadena. Ese middleware compara la sesión con el usuario y lanza `AuthenticationException`
antes de que el agente llegue a autenticarse.

Perseguí el 401 por el middleware del agente, por el orden de prioridad de middleware y por `ResolveTenantContext` antes
de encontrarlo. Vale la pena anotarlo: **el 401 apuntaba al sitio equivocado**.

**La decisión.** `actingAsPrintAgent()` tira cabeceras y sesión y deja sólo el token. No es un truco para esquivar el
problema: es parecerse al cliente real. Un agente —el puente de Flutter, el servicio de Windows— no manda `Referer` ni
cookies porque no es un navegador, y una prueba que hablara con cookies de sesión estaría probando algo que en producción
no ocurre.

Es la **tercera** corrección del arnés en esta iteración, después del `flushHeaders()` del paso 0 y el `flushSession()`
del paso 8. Las tres son la misma familia: estado del cliente de pruebas que sobrevive de una petición a la siguiente.

---

### D251 — La propina NO entra en el cambio, y el cambio se guarda

**La regla.** Mil pesos por una cuenta de 850 con 50 de propina devuelven **100**, no 150. El cambio es
`entregado − (aplicado + propina)`.

Es el error más caro de todo el cobro y el más fácil de escribir mal, porque se comete **a favor del cliente y en contra
del cajero**, todas las noches, y nadie lo reporta: el cliente se va contento y el arqueo sale corto al final del turno,
con la diferencia a nombre de quien estaba en la caja.

**Y el cambio se guarda, no se recalcula.** Lo que el cliente entregó y lo que se le devolvió son hechos, no cuentas: el
cajón ya se abrió con esa cifra. Recalcularlo después de un descuento o de una reversa daría otro número, y el cajón no
se enteraría.

**Entregar menos de lo que hay que cubrir se rechaza**, con la propina incluida en «lo que hay que cubrir». Aceptarlo
produciría un cambio negativo, que el CHECK de la base rechaza — y con razón.

---

### D252 — Sin sesión de caja no hay cobro, y la cuenta queda atada a ella

§6.3 lo dice: un pago que no pertenece a ningún turno es dinero que entró y que ningún arqueo puede explicar. **Abrir**
la cuenta sí se puede sin caja —el mesero toma la orden antes de que llegue el cajero (paso 7)— pero **cobrarla** no.

**Y la cuenta se ata a la sesión al pagarse.** Se me había olvidado, y lo destapó la FK del diario con un
`pos_session_id = 0`: sin ese enlace, una cuenta pagada no pertenece a ningún turno y el corte no puede atribuirle la
venta. Una columna nullable lo habría dejado pasar en silencio y el defecto habría aparecido en el primer corte real.

---

### D253 — El diario RECHAZA un asiento con el signo contrario a su tipo

**Lo que pasó.** El encabezado de `FinancialMovementType` avisaba desde el paso 4 de que poner un retiro en positivo es
«el error más fácil de cometer». En el paso 10 lo cometí: asenté el cambio de un cobro en positivo, dando por hecho que
`RecordFinancialMovement` aplicaría el signo. No lo aplica —la firma pide el monto **con** signo— y el resultado era un
cajón que cuadraba al revés: el «esperado» de efectivo salía mayor de lo que hay en la caja, y la diferencia se le
achacaría al cajero. Nada fallaba.

**La decisión.** `assertInvariants()` comprueba que el signo del monto coincida con `naturalSign()` del tipo. La
advertencia pasa de estar escrita a estar impuesta.

**Se comprueba en lugar de corregirse en silencio.** Aplicar el signo automáticamente sería más cómodo y escondería que
quien asienta entendió mal el sentido del movimiento — y hay casos donde eso importa.

**Una reversa lleva el signo CONTRARIO al natural, conservando el tipo.** Mi primera versión de la comprobación
rechazaba la reversa de un pago, y el patrón que ya existía era el correcto: revertir un pago de 250 es un pago de −250,
no un asiento de tipo «reversa». Conservar el tipo es lo que permite que «cuánto se pagó con tarjeta» se conteste
sumando los asientos de pago con las correcciones incluidas.

`naturalSign() === 0` —diferencia de corte, reversa como tipo— significa «cualquiera de los dos es legítimo»: una
diferencia puede sobrar o faltar.

**Dos pruebas existentes pasaban montos que la producción nunca produce** (un retiro y un depósito en positivo) y ahora
llevan el signo correcto. Que hayan podido pasar durante dos pasos es justamente el argumento para tener el invariante.

---

### D254 — El asiento de un pago cuelga del PAGO, no de la cuenta

La idempotencia del diario es por `(documento, tipo)`. Con la cuenta como origen, dos líneas de pago de la misma cuenta
—mitad efectivo, mitad tarjeta— chocarían con la misma llave y **sólo se asentaría la primera**: el corte perdería la
mitad del dinero, sin que nada fallara.

Es una consecuencia no obvia de una decisión tomada en el paso 4, y aparece sólo cuando existe el cobro multi-línea. La
venta sí cuelga de la cuenta, porque hay una venta por cuenta.

---

### D255 — `PosAccountPaid` lleva las líneas de pago, porque leerlas cerraría un ciclo

**El intento.** Escribí primero un oyente en `Finance` que leía `pos_payments` para desglosar por método, con el
argumento de que «el desglose debe salir de la evidencia y no de una copia».

**El problema.** `Pos` ya depende de `Finance` desde el paso 6 (los métodos de pago, el diario). Importar `PosPayment`
en `Finance` cerraría el círculo, y el acoplamiento en ambos sentidos entre el punto de venta y el dinero es exactamente
lo que ADR-001 evita.

**Y el argumento estaba mal planteado.** El desglose de pagos **es el hecho**, no una copia del hecho: qué se pagó, con
qué método y cuánta propina lleva cada línea es lo que ocurrió. Va en primitivos como pide D231, igual que
`PosItemsCancelled` lleva sus items desde el paso 8. Lo que sí sería duplicar un documento es meter el ticket rendido en
un evento, y eso sigue sin hacerse.

**`payment_method_id` viaja como id interno**, excepción consciente a «nunca ids secuenciales»: esa regla protege lo que
se **expone** por la API, y esto no sale de la aplicación — va del POS a Finanzas, y `payment_methods` es una tabla de
Finanzas.

---

### D256 — La mesa se libera al pagar de forma SÍNCRONA, no por evento

La tabla de eventos del diseño (§7.2) listaba a `Floor` entre los oyentes de `PosAccountPaid`, para liberar la mesa. Se
hace en la transacción del cobro, por la misma razón de D239: el estado de una mesa lo mira la pantalla de piso para
decidir dónde sentar, y «¿queda alguna cuenta viva en esta mesa?» es una pregunta sobre **cuentas**, que es lo que `Pos`
sabe contestar.

La frontera se respeta igual: qué significa liberar —libre o por limpiar, y qué pasa con la unión temporal— lo sigue
decidiendo `Floor` en `TableOccupancy`. Lo que cambia es que la llamada es directa y no diferida.

Es una desviación explícita del diseño, y queda anotada como tal.

---

### D257 — El PIN de un descuento se pide SIEMPRE, incluso a quien tiene el permiso

**La decisión.** Descontar exige token de autorización sin excepción y sin umbral de monto, aunque quien opera tenga el
permiso.

**Por qué no es desconfianza hacia el gerente.** El permiso lo tiene la **sesión** —una terminal abierta que cualquiera
puede tocar mientras su dueño atiende una mesa— y el PIN lo tiene la **persona**. Lo que se registra es quién estaba
delante en ese momento, que es la única pregunta que un reporte de robo hormiga puede contestar.

**Sin umbral, a diferencia de las mermas** (D27, D170). Un umbral sería tentador —«los descuentos chicos no molestan a
nadie»— y abriría exactamente la puerta que esto cierra: veinte descuentos pequeños en un turno, cada uno por debajo del
límite.

**La ruta pide `pos.orders.create` y el PIN pide el permiso real.** Al revés no funcionaría: exigir
`pos.discounts.apply_account` en la ruta impediría que un mesero pidiera la autorización de su gerente, que es
exactamente el flujo que §6.3 describe. Y el permiso del PIN cambia según el caso —`apply_item`, `apply_account`,
`courtesy`— porque son tres decisiones distintas y un negocio puede querer repartirlas.

**Se guardan las DOS personas** en columnas distintas. No es redundancia: el patrón que el reporte busca es «el mismo
mesero pidiendo autorización veinte veces por turno», y con una sola columna esa pregunta no se puede hacer.

---

### D258 — El monto de un descuento lo calcula el servidor, sobre la base VIVA

**El cliente manda el tipo y el valor** —«10 %», «50 pesos», «cortesía»— y nunca el resultado. Si mandara el monto, un
«10 %» podría llegar como cualquier cifra desde la consola del navegador. §6.9 lo dice en general; aquí es donde más caro
sale ignorarlo, porque el descuento es la vía más común de sacar dinero de un restaurante sin que parezca robo.

**`value` y `resulting_amount` se guardan los dos.** El primero es lo que se pidió, el segundo lo que costó. Guardar sólo
el resultado perdería la intención —«¿fue un 10 % o cincuenta pesos?»— y guardar sólo el valor obligaría a recalcular
sobre una base que pudo cambiar.

**La base es la VIVA, no el importe original.** Dos descuentos del 50 % sobre lo mismo dejan un 25 % del precio, no cero.
Es lo que espera quien opera —«otro 50 % encima»— y es lo que impide que dos descuentos sumen más que la cuenta.

**Nunca más que la base.** Un descuento de 500 sobre una línea de 45 dejaría un total negativo, y el CHECK de la base ni
lo vería porque el monto en sí es positivo.

---

### D259 — Descuento de ITEM y descuento de CUENTA se restan en sitios distintos

Es la distinción que hay que tener clara o el total sale mal **en las dos direcciones**:

- Un descuento de **item** se escribe en `pos_order_items.discount_amount`, y `line_total` —columna generada— ya lo
  resta. Volver a restarlo en el recálculo lo descontaría dos veces.
- Uno de **cuenta** no tiene línea donde vivir. Si no lo restara el recálculo, no se restaría en ningún sitio y el
  cliente pagaría el total sin descuento.

`discount_total` de la cuenta suma los dos alcances, porque al cliente le da igual dónde vivan.

El alcance se modela como `pos_order_item_id` nullable y no con una columna `scope` aparte: con dos fuentes para la
misma verdad podrían contradecirse, y con el nullable la ausencia de item **es** el alcance.

---

### D260 — Una cortesía no es un descuento del 100 %, y descontar exige caja abierta

**Aritméticamente lo es; operativamente no.** Tiene tipo propio por dos razones: se cuenta aparte —«cuánto regalé» y
«cuánto descontué» son dos preguntas, y el reporte de §9 necesita hacerlas por separado— y marca la línea con
`is_courtesy`, de donde sale que una cortesía **sí descuente inventario** (§6.3): el plato se preparó y los insumos se
gastaron aunque no se cobrara.

**Una cortesía es siempre de un item**, con CHECK en la base. Regalar la mesa entera es un descuento del 100 %, que sí
existe y deja rastro como tal.

**Y descontar exige caja abierta**, igual que cobrar (D252). Un descuento es dinero que se dejó de cobrar y el corte
tiene que poder explicarlo; el propio diario lo impone, porque `Discount` y `Courtesy` declaran `requiresSession()`. La
resolución de «la caja abierta de esta sucursal» se extrajo a `ResolveOpenSession` para que el cobro y el descuento
compartan la regla — el día que el paso 19 traiga el corte por terminal, cambia en un solo sitio.

**No se descuenta una cuenta ya pagada.** Cobrar el total, descontar después y quedarse la diferencia es exactamente la
maniobra que §6.3 quiere impedir. Corregir de más se hace con una reversa del pago.

### D261 — El candado de fronteras acertaba por accidente: una CADENA no es una dependencia

**Cómo apareció.** El oyente del descuento fue marcado como `Finance → Pos` no declarado. Los otros dos oyentes de
`Finance` hacen exactamente lo mismo —escriben el `sourceType` del asiento con el nombre de una clase de `Pos`— y
llevaban desde el paso 4 pasando sin problema.

**La diferencia era el TECLEO.** El candado busca el texto `App\Modules\X\` en el archivo. Un nombre de clase escrito
como cadena admite dos formas equivalentes —`'App\Modules\Pos\Modelo'` y `'App\Modules\Pos\Modelo'` producen el
mismo valor, porque en una cadena simple una barra doble y una sencilla dan lo mismo cuando no preceden a una comilla— y
sólo la primera coincide con el patrón. Dos archivos con el mismo comportamiento, uno marcado y otro no.

Es la peor clase de candado: el que acierta por casualidad, y que por tanto también puede fallar por casualidad.

**La regla correcta.** `sourceType: 'App\Modules\Pos\...\PosDiscount'` es cómo ADR-004 registra la procedencia de un
asiento: es un **dato**, no una llamada. No importa la clase, no la instancia, no la usa. Exigir que `Finance` declarara
depender de `Pos` por eso crearía justo el ciclo que el evento del kernel existe para evitar (D255).

Lo que sí es dependencia es el código: un `use`, un tipo, una llamada estática. El candado ahora quita las cadenas antes
de buscar, y quedó verificado rompiéndolo: con un `use` de verdad falla, con la cadena de procedencia pasa.

**Lo que NO cubre, dicho para que nadie confíe de más.** Quitar cadenas con una expresión regular no es analizar PHP: un
FQCN armado por concatenación se escaparía. Los comentarios **sí** se conservan a propósito — si un archivo habla de otro
módulo en su documentación, algo sabe de él.

---

### D262 — Dividir reparte el IMPORTE, y el centavo que sobra se le carga a la primera parte

**Dividir no reparte items** (§6.3). Repartirlos sería no poder dividir: una botella que nadie pidió individualmente no
se puede asignar a nadie. La división crea N subcuentas que cuelgan de la madre, cada una con su parte del total, y **la
mercancía se queda en la madre**.

Consecuencias que hay que aceptar juntas:

- Una subcuenta **no se recalcula**: no tiene items propios, así que un recálculo la dejaría en cero y el cliente de la
  parte 2 de 4 no pagaría nada. Su importe se escribe una vez, al dividir, y es fijo por definición.
- Una subcuenta **no tiene mesa**: la sigue ocupando la madre. Dos cuentas apuntando a la misma mesa harían que
  liberarla dependiera de cuál se cobrara primero.
- La madre queda **pagada cuando todas sus partes lo están**, y ahí se libera la mesa. No emite su propio ticket ni su
  propio evento: el dinero ya se asentó parte por parte, y volver a asentar su total contaría la venta dos veces.
- El ticket de cada parte dice «parte 2 de 4» y no itemiza: es la contrapartida honesta de repartir importe. Quien
  quiera un desglose por persona tiene que mover items, que es otra operación.

**El centavo.** 100 entre 3 son 33.33 tres veces: 99.99. El que falta no puede evaporarse —el negocio cobraría de menos
en cada división— ni repartirse, porque el peso no se divide más. Se le carga a la **primera** parte. Es arbitrario y es
honesto: alguien paga el centavo, y decidirlo aquí evita que reaparezca al final como un descuadre sin explicación.

---

### D263 — Ninguna operación de cuenta toca una cuenta con pagos aplicados

Dividir, juntar y mover items exigen que la cuenta **no tenga ni un pago**.

**Por qué.** Mover mercancía dejaría el dinero donde estaba: el ticket ya impreso diría una cosa y la cuenta otra. Y
sobre todo protege lo que D233 compró al congelar la propina en la línea de pago — juntar dos cuentas a las 22:00 no
puede tocar las propinas cobradas a las 21:00, y la forma de garantizarlo no es un caso especial sino esta regla.

Corregir un cobro es una **reversa**, no una mudanza.

**Al juntar, la cuenta de origen queda `cancelled` con su motivo** («Juntada en la cuenta A-7»). No «pagada» —no entró
dinero— ni borrada —ocurrió, y su historial la cita—. Es el estado honesto: ya no hay nada que cobrar ahí, y el motivo
dice a dónde se fue.

**Y la ORDEN no se mueve con el item.** La orden describe lo que se preparó: la comanda ya salió por la impresora de la
cocina y ese hecho no cambia de dueño. Sólo cambia `pos_account_id`, que es exactamente para lo que la columna
denormalizada existe desde el paso 7.

---

### D264 — El historial de operaciones existe para cerrar «el hueco del bar»

Sin `pos_account_operations`, **mover un item a otra cuenta es indistinguible de haberlo capturado allí desde el
principio**. La maniobra: se capturan cuatro cervezas en la mesa 3, se mueven tres a otra cuenta, esa cuenta se cancela,
y la mesa 3 paga una. Nada en `pos_order_items` delata el movimiento — la línea simplemente está en otra cuenta.

El detalle guarda `from_account_id` y `to_account_id` **por item** y no sólo en la cabecera, porque una operación puede
tener varias procedencias: juntar tres cuentas en una es un solo hecho con tres orígenes.

`detail_count` va denormalizado para que la pantalla del historial no haga un `COUNT` por renglón. Se puede permitir
porque la operación es **inmutable**: no hay forma de que se desincronice.

**El bloqueo de las dos cuentas va siempre en el mismo orden** (por id). Dos operaciones simultáneas que muevan items
entre A y B en direcciones opuestas se bloquearían mutuamente si cada una tomara primero «su» cuenta; ordenar hace que
la segunda espere en lugar de morir por interbloqueo.

---

### D265 — `displayName()` no puede disparar carga perezosa

**El defecto.** `displayName()` preguntaba `if ($this->restaurantTable !== null)`, lo que toca la relación aunque la
cuenta no tenga mesa. La carga perezosa está prohibida en este proyecto, así que la comprobación reventaba con
`LazyLoadingViolationException` — y lo hacía **desde dentro de un mensaje de error**, convirtiendo un 409 explicativo en
un 500.

Es una forma particularmente mala de fallar: el camino feliz funciona y el que se rompe es el que existe para explicar
un problema.

**El arreglo, en dos mitades.** `displayName()` mira `table_id` antes de tocar la relación —una cuenta de barra ya no la
toca nunca— y las consultas que bloquean cuentas para operar sobre ellas cargan `restaurantTable` explícitamente, porque
sus mensajes de error nombran la cuenta.

---

### D266 — `LiveServiceProbe`: una PREGUNTA que cruza la frontera, con la dependencia invertida

**El hueco.** Liberar una mesa a mano no comprobaba si tenía cuentas vivas. El propio controlador lo tenía anotado desde
el paso 5 —«lo comprobará el POS cuando existan las cuentas»— y el paso 13 es donde toca cerrarlo.

Sin la comprobación, liberar una mesa con una cuenta abierta la deja **huérfana**: el siguiente cliente se sienta ahí, el
mesero abre otra cuenta, y las dos conviven sobre la misma mesa hasta que alguien cobra una y se olvida de la otra.

**El problema de fronteras.** La respuesta la sabe `Pos`; la pregunta la hace `Floor`. Y `Pos` ya depende de `Floor`
desde el paso 7, así que consultar al revés cerraría un ciclo entre el salón y el punto de venta.

**La decisión.** El contrato vive en el kernel —`Shared\Domain\Contracts\LiveServiceProbe`—, `Floor` depende de la
interfaz y `Pos` la implementa y la registra. Ninguno de los dos módulos conoce al otro; los dos conocen el kernel, que
no conoce a nadie.

Es el tercer contrato que cruza fronteras y vive en el kernel, después de los eventos (D231) y de
`RequiresAuthorizationException`. La forma que faltaba: un evento **anuncia** algo que ya pasó, una excepción **informa**
de algo que no se pudo hacer, y esto **pregunta** algo que hay que saber antes de decidir.

**Y por qué no un evento.** Un evento no sirve para preguntar: quien libera necesita la respuesta antes de escribir, en
la misma petición y con la certeza de que es la de ahora. Es el criterio que D239 dejó fijado — el evento es para el
efecto que puede llegar tarde.

**Sin binding, el contenedor revienta**, y es lo correcto: es preferible un error ruidoso a un valor por omisión que
dijera «no hay servicio» y dejara liberar mesas ocupadas.

---

### D267 — Mover una cuenta de mesa: primero se ocupa la nueva, luego se libera la vieja

**La operación que faltaba.** «Nos pasamos a la mesa del fondo» ocurre en cada servicio, y hasta el paso 13 la única
salida era cancelar la cuenta y volver a capturar todo — que además pide PIN por cada item ya comandado (D242). Sirve
también para asignarle mesa a una cuenta de barra.

**El orden no es casual.** Al revés dejaría un instante con las dos mesas libres, y otro mesero podría sentar gente en la
de destino. Ocupando primero, la de destino queda tomada antes de soltar nada; si no estuviera disponible,
`TableOccupancy` lanza y la transacción deshace todo **sin haber liberado la original**.

**La etiqueta libre se borra al asignar mesa.** Conservar las dos haría que `displayName()` tuviera que elegir entre dos
identidades, que es justo lo que el invariante del paso 7 impide desde el alta.

**Y la mesa que se deja se libera por la puerta del salón**, con `floor.use_cleaning_state` incluido: mover una cuenta
usa el mismo camino que cobrarla (D239).

Al implementarlo, la primera versión rellenaba a mano el `table_id` viejo en un modelo refrescado para reusar
`releaseTableIfEmpty`. Funcionaba y era un truco: cualquiera que leyera esa línea después tendría que reconstruir por qué
el modelo miente sobre su propia mesa. Se extrajo `releaseIfNoLiveAccounts(int $tableId, ...)`, que recibe la mesa.

---

### D268 — El número de mostrador es un contador diario propio, no un folio

**Por qué el folio de la cuenta no sirve.** Es un número que crece para siempre: a los tres meses va por A-14238. Nadie
grita eso, y quien lo oye no lo retiene. §6.3 pide «numeración visible» y eso significa dos cifras que vuelven a 1 cada
jornada.

**Por qué una tabla y no `MAX(takeout_number) + 1`.** Dos pedidos simultáneos leerían el mismo máximo y gritarían el
mismo número: dos personas levantándose por la misma bolsa. Con una fila por (negocio, sucursal, jornada) y `FOR UPDATE`,
el segundo espera.

**Y por qué NO se reutiliza `DocumentNumberAllocator`**, que resuelve exactamente el mismo problema de concurrencia:
aquél **no reinicia nunca**, y el reinicio diario es el requisito entero. Forzarlo borrando su fila cada noche rompería
su invariante de «sin huecos» y mezclaría dos conceptos que se leen igual y significan cosas distintas — un folio
identifica un documento, esto es una etiqueta que se recicla.

**El número se asigna DENTRO de la transacción de la cuenta.** Si la cuenta no llega a crearse, el número tampoco se
consume. Reservarlo antes dejaría huecos cada vez que alguien empieza un pedido y se arrepiente, y un hueco en el
mostrador es un número que se grita y nadie recoge.

**La jornada la fija la zona horaria de la SUCURSAL.** Un pedido de la 1:30 de la madrugada en Ciudad de México
pertenece al día anterior para quien opera, y a la fecha siguiente si se calcula en UTC — que es como el mostrador
acabaría gritando el número 1 a medianoche con quince pedidos activos.

**Lo que esto NO resuelve, dicho ahora:** un negocio que cierra a las 3 de la madrugada verá el contador reiniciarse a
medianoche, con pedidos activos del «día anterior» conviviendo con números nuevos. Atarlo al turno de caja sería más fino
y no se puede: un pedido para llevar se toma sin caja abierta, igual que una cuenta (D252), así que el mostrador dejaría
de numerar cuando el cajero sale a comer. Cuando aparezca el caso se resuelve con una hora de corte de jornada
configurable — una llave, no un rediseño.

---

### D269 — Entregar y cobrar son hechos independientes

`pending → ready → delivered`, con `pos.takeout.manage`, y **separado del cobro**. `pos.takeout_payment_timing` decide
si se cobra al ordenar o al recoger, así que atar el estado de entrega al cobro haría que un negocio que cobra al recoger
no pudiera marcar nada como listo hasta tener el dinero — justo al revés de como funciona un mostrador.

**Se puede saltar de `pending` a `delivered`:** el cliente estaba esperando de pie y se lo dieron en cuanto salió.
Obligar a pasar por «listo» sería un toque de más en el momento de más prisa.

**De `delivered` no se retrocede.** Entregar es un hecho físico: la bolsa salió por el mostrador, y deshacerlo en el
sistema no la trae de vuelta. Si se entregó al cliente equivocado, lo que hay es un problema nuevo — no un estado
anterior.

---

### D270 — Un candado de transacción sólo se puede probar fuera de `Feature`

**El error.** Escribí la comprobación de «exige transacción abierta» del asignador de mostrador como prueba de
integración, y **no falló nunca**: `RefreshDatabase` envuelve cada prueba en una transacción, así que `transactionLevel()`
jamás vale 0 ahí.

Lo notable es que ya estaba advertido. El encabezado de `DocumentNumberAllocatorGuardTest`, escrito una iteración antes,
dice exactamente esto — «un candado que ninguna prueba puede activar es un candado que nadie sabe si funciona»— y aun
así repetí el error al escribir el segundo asignador.

**La regla, ahora explícita:** un candado que depende del estado de la conexión se prueba en `Unit`, con un doble de la
conexión. Es la segunda vez en esta iteración que una advertencia escrita no impidió repetir el fallo (la otra fue el
signo del diario, D253), y las dos veces la salida fue la misma: convertir la advertencia en algo que falla solo.

---

### D271 — `PosAccountPaid` lleva también los items vendidos, y por la regla del diseño

Igual que con las líneas de pago (D255), y esta vez la razón está escrita en §7.1 del propio diseño: **nadie declara
depender de un módulo operativo**. `Inventory` es un módulo de dominio, así que leer `pos_order_items` desde su job
metería una flecha hacia arriba que la regla 2 de §2 prohíbe.

Merece anotarse porque la tentación es distinta que en el caso de `Finance`: aquí **no hay ciclo** —`Pos` no depende de
`Inventory`— así que la dependencia «funcionaría». Lo que la impide es la capa, no el ciclo.

El evento incluye las **cortesías**: el plato se preparó y los insumos se gastaron aunque no se cobrara (§6.3). Y excluye
los cancelados, que ya generaron su merma por su propio camino si la merecían (D242).

---

### D272 — El descuento de inventario es asíncrono, y eso NO es una optimización

§6.2 dice que el POS nunca se bloquea por inventario. La razón no es la velocidad: un platillo con receta de tres
niveles puede tocar veinte artículos, y cualquiera puede tener una receta mal capturada o un ciclo. Si eso corriera
dentro del cobro, **un error de receta impediría cobrar** — alguien con el cambio en la mano y una pantalla que dice que
no se pudo.

El dinero entra primero y el inventario se pone al día después. La contrapartida está aceptada desde §6.2: existencias
negativas permitidas y unos segundos de atraso.

**El oyente sólo encola**, y ni siquiera encolar puede tumbar el cobro: si la cola estuviera caída, se registra y no se
propaga.

**Dentro del job, un item que revienta no detiene a los demás.** Un platillo mal capturado no puede impedir que se
descuenten los otros diecinueve.

**La cola es `default` y no `critical`:** el cobro ya terminó y aquí no espera nadie. `critical` es para lo que la
operación está esperando.

**Idempotente por `pos_account:{ulid}:item:{ulid}:{componente}`.** El componente va en la llave porque un platillo con
tres insumos escribe tres movimientos, y sin él el segundo chocaría con el primero y sólo se descontaría uno. Importa
porque el mecanismo de reparación de este sistema **es** re-despachar: no hay un botón de «recalcular inventario» que
recorra la venta desde cero, y no debe haberlo — recalcular sobre un kardex que ya tiene movimientos duplicaría los que
sí se escribieron.

---

### D273 — El almacén del que se descuenta es el del ÁREA que preparó

Un platillo sale de la cocina y descuenta del almacén de la cocina; una cerveza que el mesero saca de la nevera no tiene
área y descuenta del almacén de la sucursal.

Es lo que hace que un **conteo por área** tenga sentido: sin esto todo saldría de un almacén único y el conteo de la
barra nunca cuadraría — la cerveza descontada por la cocina y contada en la barra darían una diferencia permanente que
nadie podría explicar.

Reusa la misma cascada que ya existía: `preparation_areas.warehouse_id` es una columna de la Iteración 1, y el ruteo del
item a su área se congela al capturar (D240). Así que «de qué almacén salió» queda decidido en el momento de la venta y
no se recalcula después.

**Y el consumo lo resuelve el MISMO servicio que la producción** (`ResolveProductionConsumption`). Es deliberado: si
vender un platillo consumiera distinto de producirlo, el inventario tendría dos verdades y la diferencia aparecería como
un descuadre sin causa visible.

---

### D274 — El asiento de un gasto va DENTRO de la transacción, no por evento

§7.2 del diseño listaba un evento `ExpenseRegistered` emitido por `Finance` y escuchado por `Finance`. Un evento para un
efecto **dentro del mismo módulo** no compra nada: no cruza ninguna frontera, no permite que nadie reaccione sin
conocernos, y sí añade un salto en el que el asiento se puede perder.

**Y aquí la atomicidad es lo correcto, no sólo lo posible.** Los eventos del POS corren después del commit porque un
fallo al asentar **no puede tumbar un cobro** (D220): el dinero ya entró y decirle al cliente que no se pudo sería
mentirle. Un gasto es distinto — es una operación de finanzas de principio a fin, y un gasto registrado sin su asiento
sería dinero que salió y que el corte no conoce, que es exactamente lo que este registro existe para evitar.

Una transacción, dos escrituras, o ninguna. Es una desviación explícita del diseño.

---

### D275 — El gasto tiene umbral; el cajón, no. Y no es incoherencia

Abrir el cajón pide PIN **siempre** (D248) y un gasto sólo por encima de un monto configurable. La diferencia está en
qué pasa si el sistema exige demasiado:

- Si **todo gasto** pidiera PIN, el cajero dejaría de registrar los 40 pesos de hielo para no ir a buscar al gerente. El
  dinero sale igual y el arqueo se descuadra **sin rastro** — peor que un gasto registrado sin autorizar.
- Con el **cajón** no existe ese riesgo: no registrar la apertura no es una opción, porque el cajón se abre o no se abre.

Es el mismo razonamiento de las mermas (D27, D170) aplicado al dinero, y el tercer uso del contrato de ADR-008 —mermas,
cierre de conteos, gastos—, lo que confirma que estaba bien planteada: tres operaciones de tres módulos comparten el
mecanismo sin conocerse.

**El umbral es por sucursal**, porque el gasto corriente de un bar y de una fonda no se parecen. **Y «en el umbral» no
pide PIN:** «hasta mil sin autorizar» es como lo lee quien lo configura.

**El comprobante es opcional** (§6.5): exigirlo haría que el gasto de 40 pesos de hielo no se registrara, y un gasto sin
comprobante es infinitamente mejor que un gasto sin registrar. El primero descuadra el arqueo de forma explicable; el
segundo lo descuadra y punto.

---

### D276 — Un gasto desde caja lleva el método de EFECTIVO en su asiento

**El defecto.** Escribí el asiento pasando `paymentMethod: null` para los gastos de caja, razonando que «sin método, el
diario decide por el tipo». Y el diario decide que **no** toca el cajón: `cashByNature()` no lista los gastos, con razón
— un gasto puede salir del cajón o de una transferencia, y el tipo solo no lo sabe.

El resultado era un gasto de caja asentado como si no tocara el efectivo: el «esperado» del arqueo salía 250 pesos más
alto de lo que hay en el cajón, y la diferencia se le achacaría al cajero. **Nada fallaba.** Es la misma familia que el
signo del cambio (D253), y otra vez la destapó una prueba que miraba una bandera y no un total.

**El arreglo es decir la verdad**, no encender la bandera: un gasto desde caja **se pagó en efectivo**, así que lleva el
método de efectivo. La bandera se enciende sola porque el dato es correcto.

---

### D277 — `CashSessionProbe`: el segundo contrato de pregunta en el kernel

`Finance` necesita saber cuál es el turno abierto de una sucursal —un gasto desde caja pertenece a un turno, y **el
turno lo resuelve el servidor**: aceptarlo del cliente dejaría que alguien cargara un gasto al turno de otro, con el
arqueo del cajero de la mañana descuadrado por el de la tarde—. La respuesta la sabe `Pos`, que ya depende de `Finance`.

Misma salida que `LiveServiceProbe` (D266): el contrato en el kernel, `Finance` depende de la interfaz, `Pos` la
implementa.

**Que aparezca dos veces en dos pasos consecutivos cambia cómo lo veo.** La primera parecía un caso particular del
salón; la segunda sugiere que es la forma normal de que un módulo de dominio necesite algo que sólo sabe uno operativo —
y que van a aparecer más. Queda como patrón nombrado y no como excepción.

**Y «no hay caja abierta» responde 409, no 422**, con una excepción propia de `Finance`: los datos que llegaron son
correctos y lo que hay que hacer es abrir la caja, no corregir el formulario. Son dos clases de excepción para el mismo
estado —`Pos` tiene la suya— porque cada módulo traduce las suyas a HTTP, y `Finance` no puede lanzar una de `Pos` sin
cerrar el ciclo que el contrato existe para evitar.

---

### D278 — El candado de modelos releídos acusó a código correcto, por segunda vez

`$x = Modelo::create([...])->refresh();` es correcto —incluso mejor que releer después, porque la variable nunca llega a
contener el modelo sin releer— y el candado lo marcaba: sólo reconocía `$x->refresh()` en una línea aparte.

Es la **segunda** vez que este candado acusa a código bueno (la primera fue el paso 4 de esta iteración). La lección no
es que esté mal pensado, sino que reconocer «está bien escrito» por análisis de texto exige **enumerar todas las formas
de escribirlo bien**, y esa lista crece con el proyecto. Cada vez que crezca hay que agregarla al candado, no reescribir
el código para que encaje en el patrón que el candado ya conoce — eso sería dejar que la herramienta dicte el estilo.

---

### D279 — El cargo al crédito es SÍNCRONO; su asiento, no

Cobrar a crédito carga el saldo del cliente **dentro de la transacción del cobro**, llamando a `Customers` directamente.
El asiento del diario va por evento, después del commit.

**La asimetría es el punto.** Si el cargo llegara tarde, una cuenta podría quedar pagada con el saldo del cliente sin
cargar: el negocio habría regalado la comida y el estado de cuenta no lo sabría. El asiento del diario sí puede esperar
—si falla, se repara re-despachando— porque la deuda ya está registrada donde importa.

Es el mismo criterio que ha ido apareciendo en toda la iteración: **el evento es para el efecto que puede llegar tarde**
(D239 con las mesas, D272 con el inventario, D274 con los gastos). Aquí el mismo hecho tiene las dos mitades a la vez, y
cada una va por su camino.

**Vive en `Pos` y no en `Customers`.** Es una operación del cobro y su condición de éxito es que la cuenta quede pagada.
`Pos` es operaciones y `Customers` dominio, así que la flecha va hacia abajo y está declarada; al revés sería la flecha
hacia arriba que §2 prohíbe. Lo que sí es de `Customers` es **escribir el saldo**, y eso pasa por su única puerta.

---

### D280 — El saldo del cliente es proyección, con el patrón del kardex

`customer_credits.balance` se reconstruye de `customer_credit_movements`, igual que `article_stocks` frente al kardex y
por la misma razón: un saldo almacenado como verdad única se desvía —una escritura a medias, un ajuste manual— y nadie
puede reconstruirlo para saber cuál era el bueno.

**`balance_after` en cada movimiento, calculado bajo lock.** Dos cargos simultáneos del mismo cliente leerían el mismo
saldo y escribirían el mismo `balance_after`: el estado de cuenta mostraría dos renglones con el mismo saldo y el segundo
cargo parecería no haber pasado. Además permite contestar «¿cuánto debía el 3 de marzo?» sin sumar la historia, y
**detectar una desviación**: si el último `balance_after` no coincide con la proyección, algo escribió por fuera.

**El signo se comprueba, no se aplica.** Un cargo suma, un abono resta, y un `adjustment` va en cualquier dirección
(signo natural cero). Misma decisión que en el diario (D253) y por lo mismo: aplicarlo en silencio escondería que quien
llama entendió mal el sentido — un abono en positivo aumentaría la deuda y nadie lo notaría hasta que el cliente
reclamara.

**Idempotente por (documento, tipo)**, misma llave que el diario: re-despachar el cargo de una cuenta fiada no duplica la
deuda.

---

### D281 — Fiar no mueve caja; abonar sí. Y el límite se suspende sin borrarse

**Dos asientos que se ven parecidos y no lo son.** `credit_granted` **no** mueve caja —no entró dinero— pero es un
derecho de cobro: es lo que distingue «vendí 10 000» de «cobré 8 000 y me deben 2 000». `credit_repayment` **sí** mueve
caja, porque el efectivo entró al cajón. Confundirlos haría que fiar aumentara el efectivo esperado del corte, que es
exactamente al revés.

Los abonos son la mitad que falta para que el corte cuadre (D235): sin ellos, un turno que recibió dos mil pesos de fiado
daría dos mil de más y nadie sabría de dónde salieron. Por eso el abono **exige turno abierto**, igual que el cobro y el
gasto.

**Límite y habilitación son dos columnas y no una.** Un cliente que se atrasó no pierde su límite, pierde el permiso de
usarlo: volver a habilitarlo no exige recapturar nada, y el historial deja ver que el límite nunca cambió — distinto de
habérselo bajado a cero y vuelto a subir.

**No se abona más de lo que se debe.** Un saldo negativo no significa nada aquí: el negocio no le debe dinero al cliente
por haber pagado de más, le debe un cambio en el momento.

**Y el disponible nunca sale negativo.** Un cliente que se pasó del límite con autorización tiene cero disponible, no
«menos doscientos» — que no significa nada para quien lo lee en el mostrador.

---

### D282 — La fila de crédito nace con el cliente, en cero

Podría crearse al asignar el primer límite, y sería peor: cada sitio que consulta el saldo tendría que contemplar
«todavía no hay fila», y ese `null` acabaría interpretándose como cero en unos lados y como error en otros.

Con límite cero, **«no puede fiar» sale del propio dato** y no de la ausencia de dato.

**El método de pago `customer_credit` sigue naciendo INACTIVO** (D232), ahora que los clientes existen. Fiar es una
decisión del negocio, no algo que se enciende solo al actualizar el sistema: un restaurante que nunca ha fiado no debería
encontrarse el botón puesto. Se activa desde la pantalla de métodos de pago, que ya existía.

---

### D283 — El disponible de propinas se calcula del DIARIO, y eso lo hizo posible una decisión del paso 10

§6.6 del diseño decía que el monto disponible se calcula «de `pos_payments` agrupado por `tip_membership_id`». Eso
habría cerrado un ciclo `Finance → Pos`, o exigido un tercer contrato de pregunta en el kernel.

No hizo falta ninguna de las dos cosas. En el paso 10, al asentar la propina de cada línea de pago, puse como actor **a
quien se le atribuye** la propina y no a quien cobró, con el argumento de que «permitirá que la liquidación agrupe por
persona directamente del diario». El dato ya estaba donde hacía falta.

Vale la pena anotarlo porque es la primera vez en la iteración que una decisión tomada por una razón declarada **paga
ocho pasos después**, y sin ella este paso habría sido notablemente más caro.

**No se almacena.** Se reconstruye: lo asentado menos lo liquidado. Misma decisión que el corte (ADR-004) — una cifra
almacenada como verdad paralela se desvía y nadie sabe cuál era la buena.

**Y las reversas se descuentan solas:** una propina de una cuenta revertida lleva el mismo tipo con signo contrario, así
que la suma la resta sin que este servicio tenga que saber de reversas.

---

### D284 — Liquidar propinas es lo que impide que el arqueo dé corto todas las noches

Las propinas entran a la caja con el resto del cobro. Cuando el cajero se las entrega al mesero al cerrar, si esa salida
no está registrada el arqueo da corto por una cantidad que ningún movimiento explica — y como pasa **todas las noches**,
la diferencia deja de mirarse. Es la cuarta cosa que D235 identificó como necesaria y la que menos se ve venir.

**Liquidación simple (D39):** sin reparto por porcentajes, sin pool entre meseros, sin retención. Eso es política laboral
y varía por negocio; lo que entra es el hecho — a quién se le pagó cuánto y quién se lo entregó, en dos columnas
distintas porque son dos personas.

**El disponible se recalcula DENTRO de la transacción**, no se acepta del cliente: entre que la pantalla lo mostró y el
cajero apretó el botón, otra terminal pudo liquidar lo mismo.

**La pantalla lista «a quién le debo», no «quién ha tenido propinas».** Incluir a los que están al corriente con un cero
llenaría la lista de gente a la que no hay que pagar, y en un turno con quince meseros eso la vuelve inútil.

---

### D285 — El depósito NO exige caja abierta, y es la única excepción

Cobrar, descontar, gastar y liquidar propinas exigen turno abierto. Un depósito no.

**Porque quien va al banco captura el depósito horas o días después**, con el comprobante en la mano. Exigir turno
obligaría a capturarlo en el momento del retiro —cuando todavía no hay comprobante— o a inventarse una sesión abierta
para poder registrarlo.

Por eso tampoco lleva `pos_session_id`: el dinero **ya salió** de la caja cuando se retiró, y ese movimiento sí
pertenece a un turno. El depósito es el otro extremo del viaje.

**Cierra el retiro** (D38): sin él, un retiro de diez mil pesos es una salida declarada que no llega a ningún sitio — el
arqueo cuadra porque el dinero salió, y nadie puede decir dónde está.

**Referencia bancaria simple, sin conciliación.** Conciliar exige leer archivos del banco, formatos por institución y un
motor de emparejamiento: una iteración entera. Lo que hace falta ahora es contestar «¿este retiro llegó al banco?», y
para eso basta el folio del comprobante — que por eso es obligatorio.

**`deposited_on` es DATE y no timestamp:** un depósito se hace en un día, no en un instante, y guardar un timestamp
obligaría a inventarse una hora que nadie capturó.

---

---

## Pendiente de diseño abierto por la UI

| Pendiente | Estado |
|---|---|
| ~~Guardar el **ULID de la entidad auditada** en el propio asiento (`auditable_ulid`)~~ | **Cerrado** (D151). Se aprobó explícitamente y se implementó al cerrar la Iteración 2 |

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
| ~~El alta de personal no tiene formulario en la UI~~ | 1 | **Cerrado** (D141) |
| ~~No hay pantalla de perfil de empleado~~ | 1 | **Cerrado** (D141). Al construirla apareció que el endpoint estaba roto desde el primer día (D144) |
| ~~No hay editor del alcance por sucursal de una membresía~~ | 1 | **Cerrado** (D140). No sólo faltaba la pantalla: faltaba el endpoint, y el permiso llevaba una iteración entera sin ruta |
