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

---

## Pendiente de diseño abierto por la UI

| Pendiente | Por qué no se resolvió aquí |
|---|---|
| Guardar el **ULID de la entidad auditada** en el propio asiento (`auditable_ulid`) | Es lo correcto: la bitácora es evidencia y debe ser autocontenida, incluso si la fila original desaparece. Resolver el ULID por fila sería una consulta por fila sobre una tabla de alto volumen. Pero es una **columna nueva en una tabla inmutable**, o sea un cambio del diseño del kernel, y eso exige aprobación explícita antes de escribir la migración (CLAUDE.md) |

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
| El alta de personal no tiene formulario en la UI | 1 | El endpoint existe y está probado; la pantalla sólo administra PIN y estado. Se completa junto con la pantalla de perfil de empleado |
| No hay editor del alcance por sucursal de una membresía | 1 | El alcance se crea por API. La pantalla llega con el alta de personal |
