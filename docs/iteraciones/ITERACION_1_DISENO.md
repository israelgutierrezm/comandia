# Iteración 1 — Shared Kernel · DISEÑO PARA APROBACIÓN

**Estado:** **diseño aprobado el 2026-08-17.** Las quince decisiones abiertas quedaron
resueltas (§12). Listo para implementación.
**Alcance:** Tenancy · Identidad y Acceso · Organización · Configuración · Auditoría · Shared.

> **Decisiones que cambiaron el diseño respecto a la propuesta original:**
> **P1** — el nombre de una persona sin credenciales vive en `employee_profiles`, no en la
> membresía: desaparece `tenant_memberships.display_name` y aparece la regla de resolución
> de nombre de §3.4.1 con su invariante.
> **P2** — la autorización por PIN evalúa la unión de roles del autorizador. Es una
> excepción acotada a D9 y quedó registrada en
> [ADR-008](../adr/ADR-008-autorizacion-por-pin-excepcion-rol-activo.md).

Convenciones aplicadas en todo el documento (ARQUITECTURA_MAESTRA §7):

- PK `BIGINT UNSIGNED AUTO_INCREMENT`; ULID público sólo en entidades expuestas por API.
- `tenant_id` `BIGINT UNSIGNED NOT NULL` en toda tabla de dominio (ADR-002, Regla A). Las tres excepciones están declaradas en §1.
- Timestamps en UTC. `created_at`/`updated_at` `TIMESTAMP NULL` salvo indicación.
- Sin JSON salvo `audit_entries.before/after`.
- Todo índice lleva justificación. Índices compuestos de tablas transaccionales inician por `tenant_id`.
- Sin *soft deletes* en el kernel (§12, P11 / D80): el ciclo de vida se modela con `status`.

> **Nota de colación heredada de D58.** La base es `utf8mb4_0900_ai_ci`
> (acento-insensible y caso-insensible). Eso es correcto para nombres de artículos y
> clientes, pero **incorrecto para identificadores**: con esa colación `01hq...` y
> `01HQ...` serían el mismo valor en un índice único. Por eso **toda columna de ULID,
> hash, RFC, CURP y NSS se declara `CHARACTER SET ascii COLLATE ascii_bin`** en su
> migración. Está señalado columna por columna.

---

## 1. Excepciones declaradas a la Regla A

Tres tablas del kernel no llevan `tenant_id`, y cada una necesita justificación escrita
porque son las únicas que existirán en todo el proyecto:

| Tabla | Por qué no lleva `tenant_id` |
|---|---|
| `tenants` | Es la tabla del tenant. Su PK **es** el `tenant_id`. |
| `users` | Identidad global del SaaS: el correo es único en toda la plataforma y una persona puede pertenecer a N tenants independientes (ESPECIFICACIÓN_MAESTRA §4.1, capa 1). El aislamiento vive en `tenant_memberships`. |
| `permissions` | Catálogo **cerrado del sistema**, definido en un seeder versionado. El tenant combina permisos en roles; no inventa permisos (D10). No contiene dato de ningún tenant. |

Cualquier alta futura a esta lista es una decisión de arquitectura, no un detalle de
implementación.

---

## 2. Módulo Tenancy

### 2.1 `tenants`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | PK |
| `ulid` | CHAR(26) `ascii_bin` | no | id público |
| `name` | VARCHAR(150) | no | nombre comercial |
| `legal_name` | VARCHAR(255) | sí | razón social; la usa el SaaS para facturar al tenant |
| `slug` | VARCHAR(60) `ascii_bin` | no | superficies públicas `/m/{slug}`, `/t/{slug}` |
| `status` | ENUM | no | ver §2.2 |
| `contact_email` | VARCHAR(150) | no | contacto administrativo |
| `contact_phone` | VARCHAR(20) | sí | |
| `onboarded_at` | TIMESTAMP | sí | cuándo quedó operativo |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique**
- `ulid` — contrato del id público.
- `slug` — el slug resuelve la URL pública; dos tenants con el mismo slug harían ambiguo el menú QR. `ascii_bin` para que `mi-fonda` y `Mi-Fonda` no colisionen por la colación ai_ci de la base.

**Índices**
- `(status)` — el panel de super admin y el job de facturación filtran por estado sobre decenas de filas. Es el único índice justificable: cualquier otro sería adorno en una tabla de este tamaño.

**Inmutable:** no. El tenant se edita.

### 2.2 Estados del tenant

`ESPECIFICACIÓN_MAESTRA` §2 deja la política comercial pendiente pero exige que el
modelo de estados la soporte. Enum propuesto:

| Estado | Significado | Efecto en el sistema |
|---|---|---|
| `pending_activation` | creado por super admin, sin configurar | sólo entra el propietario; el POS no opera |
| `active` | operando | sin restricción |
| `suspended` | impago o suspensión administrativa | **ningún acceso** salvo super admin en modo soporte |
| `read_only` | impago con periodo de gracia | lectura y exportación; cero escritura de dominio |
| `pending_deletion` | baja solicitada, borrado diferido | como `suspended`, con fecha de purga |
| `cancelled` | baja consumada, datos conservados por obligación legal | sin acceso |

El middleware de contexto (§8) es quien traduce el estado a permiso de operación. La
existencia de `read_only` desde el día uno es lo que evita que el día que se decida la
política comercial haya que rediseñar el middleware.

### 2.3 `tenant_status_transitions` — INMUTABLE

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `from_status` | ENUM | sí | NULL en la creación |
| `to_status` | ENUM | no | |
| `reason` | VARCHAR(255) | sí | |
| `actor_user_id` | BIGINT UNSIGNED | sí | FK `users`; NULL si fue el sistema |
| `created_at` | TIMESTAMP | no | sin `updated_at`: append-only |

**Índices:** `(tenant_id, created_at)` — la única consulta es "historia de este tenant en orden".

**Por qué una tabla y no la bitácora de auditoría:** la bitácora tiene retención de 12
meses en caliente más archivado (D47). El historial de suspensiones y bajas es
**evidencia comercial y legal** que no puede depender de una política de archivado; una
disputa de cobro puede llegar dos años después.

### 2.4 `subscriptions`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `status` | ENUM(`active`,`past_due`,`cancelled`) | no | |
| `started_at` | DATE | no | |
| `current_period_start` | DATE | no | |
| `current_period_end` | DATE | no | ancla de facturación |
| `cancelled_at` | DATE | sí | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `(tenant_id, status)` **no** se puede hacer único porque habrá
suscripciones canceladas históricas. La regla "una activa a la vez" se valida en el
servicio de aplicación.

**Índices:** `(tenant_id, status)` — resolver "la suscripción vigente de este tenant".

No hay precios ni importes: el cobro real llega al final (D4) y meter montos hoy sería
inventar la forma comercial.

### 2.5 `tenant_limits`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `limit_key` | VARCHAR(60) `ascii_bin` | no | catálogo cerrado en código |
| `limit_value` | INT UNSIGNED | sí | **NULL = sin límite** |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `(tenant_id, limit_key)`.
**Índices:** ninguno más. Se lee siempre por tenant completo y se cachea.

Catálogo inicial de llaves: `max_users`, `max_branches`, `max_warehouses`,
`max_terminals_per_branch`.

**Por qué tabla propia y no columnas ni el sistema de configuración:**
- No columnas, porque la forma comercial no está definida (D4) y cada límite nuevo sería una migración.
- **No el sistema de configuración jerárquica**, aunque técnicamente cabría: los límites los fija el **super admin** y el tenant no puede tocarlos ni con permiso. Ponerlos en la misma tabla que los ajustes del tenant obliga a defender esa frontera con lógica en cada escritura; separarlos la hace estructural.

**Medición del uso: calculada, no almacenada.** `max_users` se compara contra un
`COUNT` de membresías activas, no contra un contador. Es el mismo principio de ADR-004
—los cortes se calculan— por la misma razón: un contador se desincroniza y entonces hay
dos verdades y ninguna forma de saber cuál miente.

### 2.6 `tenant_modules`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `module` | VARCHAR(40) `ascii_bin` | no | valor del registro de `config/comandia.php` |
| `is_enabled` | BOOLEAN | no | default 0 |
| `enabled_at` | TIMESTAMP | sí | |
| `disabled_at` | TIMESTAMP | sí | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `(tenant_id, module)`.
**Índices:** ninguno más; se lee el conjunto completo del tenant y se cachea con la
configuración.

Sólo se materializan los módulos **activables** (`DigitalMenus`, `Ecommerce`). Los del
núcleo no tienen fila: preguntar si el POS está activo no tiene sentido, y una fila que
siempre vale `true` es una invitación a apagarla por error.

Se guarda `is_enabled` con fechas en lugar de borrar la fila, porque "cuándo contrató y
cuándo canceló el e-commerce" es información comercial.

---

## 3. Módulo Identity

### 3.1 `users` — global, sin `tenant_id`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `first_name` | VARCHAR(60) | no | nombre(s) |
| `paternal_surname` | VARCHAR(60) | no | apellido paterno |
| `maternal_surname` | VARCHAR(60) | sí | apellido materno — NULL para extranjeros |
| `email` | VARCHAR(150) | no | |
| `email_verified_at` | TIMESTAMP | sí | |
| `password` | VARCHAR(255) `ascii_bin` | no | hash |
| `remember_token` | VARCHAR(100) `ascii_bin` | sí | |
| `two_factor_secret` | TEXT | sí | cifrado en reposo |
| `two_factor_recovery_codes` | TEXT | sí | cifrado en reposo |
| `two_factor_confirmed_at` | TIMESTAMP | sí | |
| `is_super_admin` | BOOLEAN | no | default 0 |
| `last_login_at` | TIMESTAMP | sí | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `email` — correo único en todo el SaaS (§4.1). Con la colación ai_ci
de la base, `Ana@x.com` y `ana@x.com` colisionan, que es **el comportamiento correcto**
para correos y por eso esta columna sí conserva la colación por defecto.

**Índices:** ninguno adicional. `is_super_admin` no se indexa: son un puñado de filas y
el filtro nunca es selectivo en el sentido útil.

**Nombre por partes en tres campos y no en dos:** el mercado es México exclusivamente
(§2) y el apellido materno es un campo real en CURP, RFC y nómina. Guardarlo dentro de
"apellidos" obligaría a partirlo después con heurísticas, justo cuando se necesite para
timbrar.

**`is_super_admin` como columna y no como rol de Spatie** (D68): con `teams` activado, un
rol global exige `roles.tenant_id` nullable, y eso abre la puerta a roles sin tenant —lo
contrario de la Regla A—. El super admin ya vive fuera del dominio por ADR-002, así que
compartir mecanismo de autorización con él sería una falsa economía.

### 3.2 `tenant_memberships`

Capa 2 de identidad. Es **la** tabla del aislamiento: la pertenencia vive aquí.

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `user_id` | BIGINT UNSIGNED | **sí** | FK `users`. **NULL = empleado sin credenciales** |
| `employee_code` | VARCHAR(20) `ascii_bin` | sí | número de empleado del tenant |
| `status` | ENUM(`invited`,`active`,`suspended`,`terminated`) | no | |
| `default_role_id` | BIGINT UNSIGNED | sí | FK `roles` |
| `has_all_branches` | BOOLEAN | no | default 0 — ver §3.3 |
| `last_active_branch_id` | BIGINT UNSIGNED | sí | FK `branches`, comodidad de UX |
| `pin_hash` | VARCHAR(255) `ascii_bin` | sí | NULL = sin PIN asignado |
| `pin_set_at` | TIMESTAMP | sí | |
| `pin_failed_attempts` | TINYINT UNSIGNED | no | default 0 |
| `pin_locked_until` | TIMESTAMP | sí | bloqueo por intentos (D54) |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique**
- `ulid`.
- `(tenant_id, user_id)` — una persona tiene **una** membresía por tenant. MySQL permite múltiples NULL en un índice único, así que los empleados sin credenciales conviven sin estorbarse.
- `(tenant_id, employee_code)` — el número de empleado es único dentro del tenant. Múltiples NULL permitidos para quien no lo use.

**Índices**
- `(tenant_id, status)` — el listado de personal filtra por estado en toda pantalla de administración.
- `(user_id)` — **el único índice del kernel que no empieza por `tenant_id`, a propósito.** Lo usa el login: "¿a qué tenants pertenece este correo?" es una consulta legítimamente cross-tenant porque ocurre **antes** de que exista contexto de tenant. Vive en el flujo de identidad, no en código de dominio, así que no viola la Regla B.

**`user_id` nullable es una decisión de negocio, no una comodidad.** ESPECIFICACIÓN
§4.1 lo pide explícitamente: el lavaloza que está en nómina y jamás inicia sesión existe
como membresía sin credenciales. Consecuencia que hay que aceptar: **el modelo no puede
asumir que toda membresía tiene usuario**, y cada consulta que hoy haga `join users`
tiene que ser `left join`.

**La membresía no guarda el nombre de la persona** (decisión **P1 / D66**): el nombre vive
en `users` cuando hay credenciales y en `employee_profiles` cuando no. La regla de
resolución y su invariante están en §3.4.1.

**El PIN vive aquí y no en `users`:** el PIN de un tenant no es el PIN de otro (§4.1).
Un mesero que trabaja en dos restaurantes tiene dos PIN, y comprometer uno no compromete
el otro.

### 3.3 `membership_branch_scopes`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `tenant_id` | BIGINT UNSIGNED | no | Regla A |
| `membership_id` | BIGINT UNSIGNED | no | FK `tenant_memberships` ON DELETE CASCADE |
| `branch_id` | BIGINT UNSIGNED | no | FK `branches` |
| `created_at` | TIMESTAMP | sí | |

**Unique:** `(membership_id, branch_id)`.
**Índices:** `(tenant_id, branch_id)` — "quién puede operar en esta sucursal", que es la consulta del alta de turno y de los reportes por sucursal.

Sin ULID: es una tabla de relación y no se expone como recurso propio.

**`has_all_branches` en la membresía, además de esta tabla:** sin esa bandera, dar de
alta una sucursal nueva **excluiría en silencio** al propietario y a los gerentes
generales, y nadie se daría cuenta hasta que alguien no encuentre la sucursal en el
selector. La bandera hace que "todas" signifique todas, incluidas las futuras.

Resolución de alcance: `has_all_branches = 1` → todas las sucursales activas del tenant;
si no, exactamente las filas de esta tabla.

**Alcance por almacén:** ESPECIFICACIÓN §4.2 menciona alcances de tenant, sucursal **y
almacén**. **P5 / D74: diferido a la Iteración 3**, con la deuda declarada.

### 3.4 `employee_profiles`

Capa 3 de identidad. Base del futuro módulo de nómina y —por la decisión **D66**— **la
fuente del nombre de toda persona sin credenciales de acceso**.

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | Regla A |
| `membership_id` | BIGINT UNSIGNED | no | FK `tenant_memberships`, 1:1 |
| `legal_first_name` | VARCHAR(60) | no | nombre legal completo, para nómina |
| `legal_paternal_surname` | VARCHAR(60) | no | |
| `legal_maternal_surname` | VARCHAR(60) | sí | |
| `is_foreigner` | BOOLEAN | no | default 0 — si 1, `curp` puede ser NULL |
| `curp` | CHAR(18) `ascii_bin` | sí | |
| `rfc` | VARCHAR(13) `ascii_bin` | sí | |
| `nss` | CHAR(11) `ascii_bin` | sí | |
| `birth_date` | DATE | sí | |
| `hired_at` | DATE | sí | |
| `terminated_at` | DATE | sí | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique**
- `ulid`.
- `membership_id` — relación 1:1 real, impuesta por el índice y no por convención.
- `(tenant_id, curp)` — dos empleados del mismo tenant no pueden compartir CURP. Múltiples NULL permitidos para extranjeros.
- `(tenant_id, rfc)` — mismo razonamiento.

**Índices:** ninguno adicional. Se accede siempre por membresía.

`ascii_bin` en CURP, RFC y NSS es obligatorio: con la colación ai_ci de la base,
`GOMA850101HDFXXX01` y `gomá850101hdfxxx01` serían el mismo valor en el índice único, y
la validación de unicidad quedaría comprometida. Se normalizan a mayúsculas en el Form
Request.

**PII sin cifrar, con acceso restringido:** ver decisión **P6 / D77**.

### 3.4.1 Resolución del nombre de una persona (D66)

Al no existir `display_name` en la membresía, el nombre tiene **dos orígenes posibles** y
hace falta una regla única, escrita, sin excepciones dispersas por el código.

**Precedencia: `employee_profiles` primero, `users` como respaldo.**

```
MembershipName::for(TenantMembership $m): PersonName
    1. si existe employee_profile  → legal_first_name / legal_paternal_surname / legal_maternal_surname
    2. si no, si existe user       → users.first_name / paternal_surname / maternal_surname
    3. si no                       → estado imposible: viola el invariante I1
```

Dos formas derivadas, y sólo dos:

| Forma | Composición | Dónde se usa |
|---|---|---|
| `short()` | nombre + apellido paterno | comandas, tickets, vista de piso, selectores del POS |
| `full()` | nombre + ambos apellidos | administración, nómina, auditoría, reportes |

**Por qué el perfil de empleado gana sobre el usuario**, y no al revés: `users` es una tabla
**global del SaaS** y el tenant no puede editarla. Si un usuario escribe su nombre como
"j ruiz" en su perfil global, con la precedencia inversa eso se imprimiría en las comandas
de todos los restaurantes donde trabaja y ninguno podría corregirlo. Con esta precedencia,
el tenant recupera el control creando el perfil de empleado, que es la pantalla donde ya
está trabajando cuando le importa cómo se ve el nombre en un ticket.

Límite residual que hay que aceptar: una membresía **con** usuario y **sin** perfil de
empleado —el caso típico del propietario, que no está en nómina— muestra su nombre global y
el tenant no puede sobrescribirlo sin crearle un perfil.

#### Invariante I1

```
tenant_memberships.user_id IS NULL  ⇒  existe employee_profiles.membership_id
```

Sin esto, una membresía sin credenciales y sin perfil sería **una persona sin nombre**: una
comanda sin mesero identificable y una fila de auditoría que no dice quién actuó.

**Cómo se impone**, y por qué así:

- No puede ser un `CHECK` de base de datos: la condición cruza dos tablas y MySQL no lo permite.
- **Se impone en el servicio de aplicación**: dar de alta una membresía sin `user_id` exige el perfil de empleado **en la misma transacción**. No hay camino que cree una sin la otra.
- **Simétricamente**, borrar el perfil de empleado de una membresía cuyo `user_id` es NULL está prohibido: dejaría a la persona sin nombre por la puerta de atrás.
- Se verifica con pruebas de feature en las dos direcciones (§10).
- Los *triggers* de MySQL como defensa en profundidad se evalúan en la Iteración 11, junto con los de inmutabilidad de la bitácora.

**Costo declarado:** mostrar el nombre de un mesero exige `LEFT JOIN users` **y**
`LEFT JOIN employee_profiles`. En la vista de piso —treinta mesas con su mesero— es una
consulta con dos *joins*, no un problema de latencia; pero con `preventLazyLoading` activo
hay que cargarlo explícitamente. El kernel expone el resolutor y un *scope* de carga para
que ningún módulo improvise su propio `COALESCE`.

Lo que esta decisión descarta: que el tenant pueda dar a la misma persona un nombre
distinto por tenant sin crearle un perfil de empleado. **No rompe ningún requisito de los
documentos maestros** —era una capacidad propuesta, no pedida—, así que su pérdida no
genera deuda.

### 3.5 Tablas de Spatie

Se publica la migración del paquete con `teams = true` y `team_foreign_key = tenant_id`
(ya configurado en la Fase 0), **más estas modificaciones**:

**`permissions`** — global, sin `tenant_id` (excepción declarada en §1).

| Columna | Notas |
|---|---|
| `id`, `name`, `guard_name` | de Spatie. Unique `(name, guard_name)` |
| `module` | **añadido** VARCHAR(40) `ascii_bin` NOT NULL — agrupación por módulo (§4.2) y filtro de "permisos de módulos inactivos no se muestran al tenant" |
| `description` | **añadido** VARCHAR(160) NOT NULL — el texto que ve el tenant al armar un rol |

Índice añadido: `(module)` — la pantalla de armado de roles lee agrupando por módulo.

**`roles`**

| Columna | Notas |
|---|---|
| `tenant_id` | de Spatie (renombrado). **NOT NULL** (D68): sin roles globales; el super admin queda fuera de Spatie |
| `name`, `guard_name` | de Spatie. Unique `(tenant_id, name, guard_name)` |
| `ulid` | **añadido** CHAR(26) `ascii_bin`, unique — se expone por API |
| `is_system` | **añadido** BOOLEAN default 0 — el rol *Propietario* no es borrable ni editable (D10) |
| `requires_two_factor` | **añadido** BOOLEAN default 0 — 2FA obligable por tenant para roles administrativos (§10.2) |
| `description` | **añadido** VARCHAR(160) NULL |

**`model_has_roles`** — pivote de Spatie con `tenant_id`. **Es el `membership_roles` del
diagrama de ARQUITECTURA_MAESTRA §4.1**: conceptualmente son los roles de la membresía,
y físicamente es `(user, tenant, role)`. Dejar el mapeo escrito evita que alguien cree
una tabla `membership_roles` paralela.

Consecuencia de que el modelo sea `User` y no `TenantMembership` —exigido por la regla
"Spatie con teams = tenant" de CLAUDE.md—: **una membresía sin usuario no puede tener
roles**. Es coherente: quien no inicia sesión no ejerce permisos.

**`model_has_permissions`** — existe porque Spatie lo requiere, y **debe permanecer
vacía**: el tenant combina permisos en roles, no asigna permisos directos (D10). Se
vigila con un test estructural (§10). Un permiso directo sería invisible para el
concepto de rol activo y rompería D9 en silencio.

**`role_has_permissions`** — sin cambios.

### 3.6 Catálogo inicial de permisos

Nomenclatura: `{módulo}.{recurso}.{acción}`. Wildcards **deshabilitados**
(`config/permission.php`) para que el catálogo sea realmente cerrado y auditable.

**Decisión D72: se siembra el catálogo completo desde la Iteración 1**, aunque los módulos
que lo consumen no existan. Es un catálogo del sistema, cuesta un `INSERT`, permite armar
los roles plantilla completos, y §4.2 ya define que los permisos de módulos inactivos
simplemente no se muestran al tenant.

Lo que **no** se siembra son permisos inventados: los de cada módulo se **afinan al llegar
su iteración**, y ese ajuste se registra como decisión. Concretamente, el seeder es
versionado y cada iteración puede agregar, renombrar o retirar permisos de su propio
módulo —nunca de otro—. Un permiso retirado se elimina del catálogo y de los roles que lo
tuvieran, en la misma migración.

**Kernel — `tenancy`**
`tenancy.subscription.view` · `tenancy.modules.view`

**Kernel — `identity`**
`identity.users.view` · `identity.users.create` · `identity.users.update` · `identity.users.suspend`
`identity.memberships.assign_roles` · `identity.memberships.manage_branch_scopes` · `identity.memberships.reset_pin`
`identity.roles.view` · `identity.roles.create` · `identity.roles.update` · `identity.roles.delete`
`identity.employee_profiles.view` · `identity.employee_profiles.manage` · `identity.employee_profiles.view_sensitive`

**Kernel — `organization`**
`organization.branches.view` · `organization.branches.manage`
`organization.warehouses.view` · `organization.warehouses.manage`
`organization.preparation_areas.view` · `organization.preparation_areas.manage`
`organization.terminals.view` · `organization.terminals.manage`

**Kernel — `configuration`**
`configuration.tenant.view` · `configuration.tenant.update`
`configuration.branch.view` · `configuration.branch.update`

**Kernel — `audit`**
`audit.entries.view` · `audit.entries.export`

**`catalog`**
`catalog.articles.view` · `catalog.articles.manage` · `catalog.articles.archive`
`catalog.prices.view` · `catalog.prices.update` · `catalog.prices.history.view`
`catalog.categories.manage` · `catalog.tags.manage` · `catalog.units.manage` · `catalog.modifiers.manage`

**`costing`**
`costing.recipes.view` · `costing.recipes.manage`
`costing.costs.view` · `costing.costs.update` · `costing.costs.history.view`
`costing.suggested_prices.view`

**`inventory`**
`inventory.stock.view` · `inventory.kardex.view`
`inventory.entries.create` · `inventory.exits.create` · `inventory.adjustments.create`
`inventory.counts.create` · `inventory.counts.close`
`inventory.waste.create` · `inventory.waste.authorize_above_threshold`
`inventory.transfers.request` · `inventory.transfers.authorize` · `inventory.transfers.prepare` · `inventory.transfers.ship` · `inventory.transfers.receive`
`inventory.lots.manage`

**`purchasing`**
`purchasing.suppliers.view` · `purchasing.suppliers.manage`
`purchasing.receipts.create` · `purchasing.supplier_prices.view`

**`pos`**
`pos.orders.create` · `pos.orders.send_to_area`
`pos.items.cancel_uncommanded` · `pos.items.cancel_commanded`
`pos.accounts.request_bill` · `pos.accounts.charge` · `pos.accounts.split` · `pos.accounts.move_items` · `pos.accounts.merge` · `pos.accounts.reopen`
`pos.discounts.apply_item` · `pos.discounts.apply_account` · `pos.discounts.courtesy`
`pos.sessions.open` · `pos.sessions.precount` · `pos.sessions.close` · `pos.sessions.withdraw`
`pos.cash_drawer.open`
`pos.takeout.manage` · `pos.credit.charge_to_customer`

**`finance`**
`finance.journal.view` · `finance.cuts.view` · `finance.cuts.close`
`finance.expenses.create_from_cash` · `finance.expenses.create_outside_cash` · `finance.expenses.authorize_above_threshold`
`finance.deposits.create` · `finance.tips.settle`
`finance.customer_credit.view` · `finance.customer_credit.manage`

**`customers`**
`customers.customers.view` · `customers.customers.manage`
`customers.fiscal_profiles.manage` · `customers.addresses.manage`

**`floor`**
`floor.layouts.view` · `floor.layouts.edit` · `floor.tables.join`

**`promotions`**
`promotions.promotions.view` · `promotions.promotions.manage` · `promotions.coupons.manage`

**`reporting`**
`reporting.exports.create` · `reporting.schedules.manage` · `reporting.saved_views.manage`
*Además*, cada definición de reporte declara su propio permiso en el namespace
`reporting.reports.{slug}.view` y se siembra al registrar la definición (ADR-006).

**`dashboards`**
`dashboards.dashboards.view` · `dashboards.dashboards.manage` · `dashboards.dashboards.publish` · `dashboards.goals.manage`

**`printing`**
`printing.jobs.view` · `printing.jobs.reprint` · `printing.jobs.retry`

**`notifications`**
`notifications.preferences.manage`

**Activables — `digital_menus`**
`digital_menus.menus.manage` · `digital_menus.pdf.generate`

**Activables — `ecommerce`**
`ecommerce.store.configure` · `ecommerce.orders.view` · `ecommerce.orders.accept` · `ecommerce.orders.reject`
`ecommerce.gateways.configure` · `ecommerce.coupons.manage` · `ecommerce.shipping_zones.manage`

### 3.7 Roles plantilla

Se crean al dar de alta el tenant y son **editables y eliminables** por el tenant, salvo
*Propietario* (D10). Criterio: mínimo privilegio (§10.3).

**Decisión D71: los seis nacen en esta iteración con el reparto de abajo, y el reparto de
los permisos de POS, inventario y finanzas se revisa contigo al construir cada módulo.**
La razón es que hoy estaríamos decidiendo si un mesero puede cancelar un platillo ya
comandado sin haber visto el flujo del POS en pantalla. El seeder queda versionado desde
esta iteración; las iteraciones 3, 4 y 5 pueden ajustar el reparto de **sus** permisos, y
cada ajuste se registra como decisión.

| Rol | `is_system` | Alcance de permisos |
|---|---|---|
| **Propietario** | sí | todos. No editable, no borrable |
| **Gerente** | no | todo salvo `identity.roles.delete`, `tenancy.*` y `ecommerce.gateways.configure`. Incluye autorizaciones sobre umbral y `pos.items.cancel_commanded` |
| **Cajero** | no | `pos.*` de cobro y sesión de caja, `pos.cash_drawer.open`, `customers.customers.*`. **Sin** descuentos ni cancelación de comandado |
| **Mesero** | no | `pos.orders.*`, `pos.items.cancel_uncommanded`, `pos.accounts.request_bill`, `floor.layouts.view`, `floor.tables.join`. **Sin** `pos.accounts.charge` |
| **Mesero con cobro** | no | lo de Mesero **más** `pos.accounts.charge` y sesión de caja (D29) |
| **Almacenista** | no | `inventory.*` salvo `authorize_above_threshold`, `purchasing.receipts.create`, `catalog.articles.view` |

Las dos plantillas de mesero existen porque cobrar es un **permiso**, no un puesto
(D29): el mismo rol base con y sin la capacidad de cerrar cuenta.

### 3.8 `personal_access_tokens` — modificada (D69)

La app Flutter y los agentes de impresión se autentican por token, sin sesión. El
`tenant_id` no puede venir de la petición (ADR-002), así que **viaja con la credencial**.

Columnas añadidas a la tabla de Sanctum:

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `tenant_id` | BIGINT UNSIGNED | no | FK `tenants` |
| `membership_id` | BIGINT UNSIGNED | no | FK `tenant_memberships` ON DELETE CASCADE |

**Índices añadidos:** `(tenant_id, membership_id)` — la consulta es "revocar todos los
tokens de esta persona en este tenant", que es la operación de baja de personal.

Consecuencias, todas deseables:

- **Un token no puede cruzar tenants ni por error.** El tenant no es un dato de la petición ni una cadena en `abilities`: es una FK verificable.
- Un usuario que trabaja en dos restaurantes necesita **dos tokens**. Correcto: son dos credenciales para dos contextos distintos, igual que tiene dos PIN.
- Dar de baja a alguien de un tenant es borrar sus tokens de ese tenant, y el `ON DELETE CASCADE` sobre la membresía lo hace solo.
- La emisión del token exige membresía `active`; el middleware la revalida en cada petición, porque una suspensión posterior a la emisión tiene que surtir efecto de inmediato.

El **rol activo y la sucursal activa no van en el token**: siguen viajando en los headers
`X-Role` y `X-Branch` validados contra el alcance de la membresía (§8.2). La diferencia es
deliberada — el tenant es una propiedad de la credencial y no se negocia; el rol y la
sucursal son elecciones legítimas del operador entre lo que ya tiene concedido.

---

## 4. Módulo Organization

### 4.1 `branches`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | |
| `code` | VARCHAR(10) `ascii_bin` | no | también es la **serie de foliación** por defecto (§7) |
| `name` | VARCHAR(120) | no | |
| `status` | ENUM(`active`,`inactive`) | no | |
| `timezone` | VARCHAR(64) `ascii_bin` | no | identificador IANA, p. ej. `America/Mexico_City` |
| `default_warehouse_id` | BIGINT UNSIGNED | sí | FK `warehouses`; se puebla tras crear el almacén |
| `street` | VARCHAR(160) | sí | |
| `exterior_number` | VARCHAR(20) | sí | |
| `interior_number` | VARCHAR(20) | sí | |
| `neighborhood` | VARCHAR(120) | sí | colonia |
| `municipality` | VARCHAR(120) | sí | |
| `state` | VARCHAR(80) | sí | |
| `postal_code` | CHAR(5) `ascii_bin` | sí | |
| `country` | CHAR(2) `ascii_bin` | no | default `MX` |
| `phone` | VARCHAR(20) | sí | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `(tenant_id, code)` — el código entra en el folio y dos sucursales con
el mismo código producirían folios ambiguos. El **nombre no es único**: dos sucursales
pueden llamarse "Centro" en ciudades distintas y prohibirlo sería una regla inventada.

**Índices:** `(tenant_id, status)` — todo selector de sucursal y todo reporte consolidado
filtra sucursales activas del tenant.

**`timezone` es columna y no llave de configuración**, aunque D20 diga que los toggles
van en el sistema jerárquico: no es un toggle, es un dato estructural de la sucursal, y
lo necesitan las consultas que calculan "el día" de un corte. Resolverlo por cascada de
configuración en cada consulta de reporte sería absurdo.

**Dirección en columnas y no en una tabla `addresses` polimórfica:** una sucursal tiene
exactamente una dirección, siempre. Los clientes tienen 0..N (D42) y ahí sí habrá tabla
propia, en la Iteración 7. Una tabla polimórfica compartida obligaría a un `join` en el
kernel para leer el domicilio de una sucursal, que es el caso más simple que existe.

**Sin datos fiscales aquí:** el perfil fiscal del tenant llega con CFDI-ready
(Iteración 7, ADR-005). Ponerlos hoy sería adivinar su forma.

### 4.2 `warehouses`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | |
| `branch_id` | BIGINT UNSIGNED | **sí** | **NULL = almacén central** |
| `kind` | ENUM(`central`,`branch`) | no | |
| `code` | VARCHAR(20) `ascii_bin` | no | |
| `name` | VARCHAR(120) | no | |
| `status` | ENUM(`active`,`inactive`) | no | |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `(tenant_id, code)`.

**Índices:** `(tenant_id, branch_id, status)` — "almacenes activos de esta sucursal" es la
consulta de toda pantalla de inventario y del alta de áreas de preparación.

**CHECK constraint** (MySQL 8 los aplica de verdad):

```sql
CONSTRAINT chk_warehouses_kind_branch CHECK (
    (kind = 'central' AND branch_id IS NULL) OR
    (kind = 'branch'  AND branch_id IS NOT NULL)
)
```

`kind` es redundante con `branch_id IS NULL` **a propósito**: hace explícito en el
modelo lo que sería una convención tácita, y el CHECK impide que las dos afirmaciones se
contradigan. Un almacén central mal marcado surtiría a todas las sucursales sin que nadie
lo hubiera decidido (D11).

**El almacén por defecto se apunta desde `branches.default_warehouse_id`**, no con un
`is_default` en esta tabla: MySQL no tiene índices únicos parciales, así que "un solo
default por sucursal" no se podría imponer desde aquí y quedaría en manos de la
aplicación. Con la FK en `branches`, la unicidad es estructural.

**Un almacén no cuenta como sucursal** para el cobro (§2): la medición de
`max_branches` cuenta `branches`, y `max_warehouses` cuenta esta tabla.

### 4.3 `preparation_areas`

Entidad de primera clase: destino de comandas **y** punto de consumo de inventario (§3).

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | |
| `branch_id` | BIGINT UNSIGNED | no | FK `branches` |
| `warehouse_id` | BIGINT UNSIGNED | no | FK `warehouses` — de dónde descuenta |
| `code` | VARCHAR(20) `ascii_bin` | no | |
| `name` | VARCHAR(80) | no | cocina, barra, parrilla… |
| `status` | ENUM(`active`,`inactive`) | no | |
| `sort_order` | SMALLINT UNSIGNED | no | default 0 — orden de aparición |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `(tenant_id, branch_id, code)`.

**Índices:** `(tenant_id, branch_id, status)` — el ruteo de comandas resuelve las áreas
activas de la sucursal en cada envío a cocina. Es la consulta más caliente de este
módulo.

**`warehouse_id` NOT NULL y no nullable con respaldo al almacén de la sucursal:** el
descuento de inventario por receta corre en la cola `critical` y no debe contener lógica
de respaldo. Si el área no dice de dónde descuenta, el job tendría que adivinarlo, y una
adivinanza en el camino del kardex es una existencia incorrecta. Al crear el área se
propone el almacén por defecto de la sucursal; el usuario puede cambiarlo pero no dejarlo
vacío. Así la topología degrada hacia lo simple sin que el modelo pierda precisión (D11).

**El ruteo a impresora no está aquí:** la impresora es del módulo Printing
(Iteración 4). El área dice *a dónde* va la comanda en términos de negocio; el dispositivo
físico es un detalle de infraestructura que cambia sin que cambie la organización.

### 4.4 `terminals`

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `ulid` | CHAR(26) `ascii_bin` | no | |
| `tenant_id` | BIGINT UNSIGNED | no | |
| `branch_id` | BIGINT UNSIGNED | no | FK `branches` |
| `code` | VARCHAR(20) `ascii_bin` | no | |
| `name` | VARCHAR(80) | no | "Caja 1", "Tableta barra" |
| `status` | ENUM(`active`,`inactive`) | no | |
| `last_seen_at` | TIMESTAMP | sí | diagnóstico de conectividad |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `ulid`; `(tenant_id, branch_id, code)`.
**Índices:** `(tenant_id, branch_id, status)` — validar el header `X-Terminal` contra las terminales activas de la sucursal, en cada request del POS.

Sin emparejamiento de dispositivo ni caja asociada: la sesión de caja y el vínculo con el
hardware son del POS (Iteración 4). Aquí la terminal es sólo una entidad de la
organización que el contexto puede validar.

---

## 5. Módulo Configuration

### 5.1 Catálogo de llaves en código

El **default de sistema vive en código**, no en base (ARQUITECTURA_MAESTRA §5). El
catálogo declara por llave: tipo, default, nivel máximo de override y permiso requerido.

```
SettingDefinition {
    key: string             // 'pos.blind_precount'
    type: bool|int|decimal|string|enum
    default: mixed
    max_scope: tenant|branch    // hasta dónde se puede sobrescribir
    module: string              // para ocultar llaves de módulos inactivos
    allowed: array|null         // valores válidos si es enum
}
```

Llaves iniciales del kernel y de los toggles ya decididos (D20). Cada iteración agrega las
suyas:

| Llave | Tipo | Default | Override hasta | Nota |
|---|---|---|---|---|
| `locale` | enum | `es_MX` | tenant | preparado sin UI (D52) |
| `currency` | enum | `MXN` | tenant | preparado sin UI (D52) |
| `tax.vat_rate` | decimal | `16.00` | **sucursal** | override por sucursal (§6.1) |
| `security.password_min_length` | int | `10` | tenant | política configurable (§10.2) |
| `security.require_two_factor_for_admin_roles` | bool | `false` | tenant | |
| `security.pin_max_attempts` | int | `5` | tenant | (D54) |
| `security.pin_lock_minutes` | int | `15` | tenant | |
| `security.terminal_session_minutes` | int | `480` | sucursal | expiración de sesión de terminal |
| `inventory.warehouse_mode` | enum | `branch_default` | sucursal | `branch_default` \| `per_area` (D11) |
| `pos.blind_precount` | bool | `true` | sucursal | recomendado (§6.3) |
| `pos.lock_items_on_bill_request` | bool | `false` | sucursal | |
| `pos.takeout_payment_timing` | enum | `on_order` | sucursal | `on_order` \| `on_pickup` |
| `pricing.rounding_mode` | enum | `none` | tenant | `none` \| `integer` \| `multiple_5` \| `multiple_10` (D15) |
| `pricing.default_markup_percent` | decimal | `200.00` | tenant | **markup sobre costo**, nunca margen (§7) |
| `ecommerce.auto_accept_orders` | bool | `false` | sucursal | (D51) |

### 5.2 `tenant_settings` y `branch_settings` — dos tablas

**`tenant_settings`**

| Columna | Tipo | Nulo |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | no |
| `tenant_id` | BIGINT UNSIGNED | no |
| `setting_key` | VARCHAR(80) `ascii_bin` | no |
| `setting_value` | VARCHAR(500) | no |
| `created_at` / `updated_at` | TIMESTAMP | sí |

Unique `(tenant_id, setting_key)`. Sin más índices: se lee el conjunto completo del
tenant una vez por request y se cachea.

**`branch_settings`** — idéntica más `branch_id BIGINT UNSIGNED NOT NULL` (FK
`branches`). Unique `(tenant_id, branch_id, setting_key)`.

**Por qué dos tablas y no una con `scope` y `branch_id` nullable:** en MySQL un índice
único trata cada NULL como distinto, así que `(tenant_id, scope, branch_id, setting_key)`
**no impediría** dos ajustes de tenant con la misma llave. Las salidas habituales
—un `branch_id = 0` centinela, o una columna generada— rompen la FK real o meten magia en
el esquema. Dos tablas dan unicidad verdadera y FKs verdaderas, al precio de una
migración casi duplicada. Ver **P8 / D78**.

**Valor como una sola columna de texto tipada por el catálogo:** el catálogo en código ya
es la autoridad sobre el tipo y valida en la escritura. Columnas `value_int`,
`value_bool`, `value_decimal` darían tipado en base que nada más aprovecha, a cambio de
que toda lectura tenga que decidir de qué columna leer. Ver **P9**.

`VARCHAR(500)` es holgado a propósito: si algún día una llave necesitara más, el problema
sería la llave, no la columna. **No se guarda JSON aquí**: una llave que necesite
estructura es una tabla que falta.

### 5.3 Resolución en cascada

```
valor efectivo = branch_settings ?? tenant_settings ?? default del catálogo (código)
```

- Cache por tenant en Redis: una entrada con todos los ajustes del tenant y una por sucursal. Se invalidan al escribir.
- Escribir una llave inexistente en el catálogo es **error**, no un `INSERT`: prohibido inventar llaves desde el cliente (§5).
- Escribir a nivel sucursal una llave cuyo `max_scope` es `tenant` es **error**.
- Todo cambio de configuración se registra en la bitácora de auditoría con antes y después (§6.7).

---

## 6. Módulo Audit

### 6.1 `audit_entries` — INMUTABLE

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | PK |
| `ulid` | CHAR(26) `ascii_bin` | no | se expone al auditor por API |
| `tenant_id` | BIGINT UNSIGNED | no | Regla A. En acciones del super admin, el tenant afectado |
| `branch_id` | BIGINT UNSIGNED | sí | FK `branches` |
| `terminal_id` | BIGINT UNSIGNED | sí | FK `terminals` |
| `actor_user_id` | BIGINT UNSIGNED | sí | NULL = acción del sistema (job, scheduler) |
| `actor_membership_id` | BIGINT UNSIGNED | sí | quién ejecutó, dueño de la sesión |
| `authorized_by_membership_id` | BIGINT UNSIGNED | sí | **quién autorizó con PIN** — el actor real de la acción sensible |
| `active_role_id` | BIGINT UNSIGNED | sí | bajo qué rol se ejecutó (D9) |
| `action` | VARCHAR(80) `ascii_bin` | no | catálogo cerrado en código |
| `auditable_type` | VARCHAR(120) `ascii_bin` | sí | morph |
| `auditable_id` | BIGINT UNSIGNED | sí | morph |
| `before` | JSON | sí | **JSON permitido aquí** |
| `after` | JSON | sí | **JSON permitido aquí** |
| `ip_address` | VARCHAR(45) `ascii_bin` | sí | soporta IPv6 |
| `user_agent` | VARCHAR(255) | sí | |
| `created_at` | TIMESTAMP(3) | no | **sin `updated_at`: append-only** |

**Las dos columnas de actor son el corazón del control antifraude.** Cuando un mesero
pide un descuento y el gerente teclea su PIN, `actor_membership_id` es el mesero y
`authorized_by_membership_id` es el gerente. Una sola columna de actor haría imposible
distinguir "el gerente aplicó el descuento" de "el gerente autorizó que el mesero lo
aplicara", y esa distinción es exactamente lo que un reporte de robo hormiga necesita
(§6.3, §9).

`active_role_id` se guarda porque con D9 el permiso efectivo depende del rol activo:
auditar la acción sin el rol deja la pregunta "¿podía hacerlo?" sin respuesta
reproducible.

**Índices** (tabla de alto volumen; cada uno se justifica y ninguno más se acepta):

| Índice | Consulta que lo justifica |
|---|---|
| `(tenant_id, created_at)` | la vista principal: "últimas acciones de este tenant" |
| `(tenant_id, auditable_type, auditable_id, created_at)` | "historia completa de esta entidad" — el caso de uso del auditor |
| `(tenant_id, actor_membership_id, created_at)` | "qué hizo esta persona" — investigación de un empleado |
| `(tenant_id, action, created_at)` | el **reporte dedicado de descuentos, cortesías y cancelaciones** exigido en §9 como mitigación del robo hormiga |

Cuatro índices en una tabla de alto volumen es un costo de escritura real. Es aceptable
porque **la escritura de auditoría es asíncrona** (cola `default`): el usuario no espera
el `INSERT`. Un quinto índice necesitaría su propia justificación escrita.

`(tenant_id, authorized_by_membership_id)` **no** se indexa: la investigación de
autorizaciones se hace sobre `action` filtrando por rango de fechas, que ya está cubierto.

**Inmutabilidad — cómo se impone:** el modelo Eloquent bloquea `update` y `delete`
lanzando excepción, y un test lo verifica. Los *triggers* de MySQL como defensa en
profundidad se evalúan en la Iteración 11: hoy añadirían un mecanismo fuera del alcance
de las pruebas.

**Retención (D47):** 12 meses en caliente más archivado. El particionamiento por fecha
está previsto como evolución; conviene dejar escrito que en MySQL la llave primaria de una
tabla particionada debe incluir la columna de partición, así que ese día habrá que pasar
la PK a `(id, created_at)`. Es factible sin dolor **porque ninguna tabla referencia
`audit_entries` con una FK**, y eso es deliberado.

### 6.2 Catálogo de acciones

`action` sale de un catálogo cerrado en código, por el mismo motivo que los permisos: un
`action` escrito a mano produce un evento que ningún reporte encuentra. Grupos iniciales:

`auth.login` · `auth.login_failed` · `auth.logout` · `auth.two_factor_enabled`
`auth.pin_authorization_granted` · `auth.pin_authorization_denied` · `auth.pin_locked`
`context.role_switched` · `context.branch_switched`
`identity.user_created` · `identity.user_suspended` · `identity.roles_assigned` · `identity.pin_reset`
`identity.role_created` · `identity.role_updated` · `identity.role_deleted`
`organization.branch_created` · `organization.branch_updated` · … (uno por entidad y acción)
`configuration.setting_updated`
`tenancy.status_changed` · `tenancy.module_enabled` · `tenancy.module_disabled` · `tenancy.limits_updated`

Cada iteración registra los suyos: `catalog.price_updated`, `pos.discount_applied`,
`pos.item_cancelled_after_command`, `inventory.waste_created`, etc.

### 6.3 Qué se audita en la Iteración 1

Según §6.7: acceso (login, fallos, 2FA), cambios de configuración, alta y cambio de
usuarios y roles, cambio de rol activo y de sucursal activa, autorizaciones por PIN
—concedidas **y denegadas**—, y cambios de estado, límites y módulos del tenant.

Los fallos también se auditan: cinco `auth.login_failed` seguidos son la señal, y sin
registrarlos no existe.

---

## 7. Módulo Shared — foliación

### 7.1 `document_sequences`

**Confirmada en esta iteración (D73)** aunque su primer consumidor llegue en la Iteración 4:
es infraestructura del kernel (§7), el mecanismo de concurrencia se puede probar con
transacciones simultáneas reales desde ya, y dejarla para después significaría meter un
lock delicado dentro de la iteración más grande del proyecto y bajo presión de entrega.

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | |
| `tenant_id` | BIGINT UNSIGNED | no | |
| `branch_id` | BIGINT UNSIGNED | no | FK `branches` |
| `document_type` | VARCHAR(30) `ascii_bin` | no | catálogo cerrado en código |
| `series` | VARCHAR(10) `ascii_bin` | no | default = `branches.code` |
| `next_number` | BIGINT UNSIGNED | no | default 1 |
| `created_at` / `updated_at` | TIMESTAMP | sí | |

**Unique:** `(tenant_id, branch_id, document_type, series)` — **es la definición misma de
la secuencia**, exactamente la tupla de §7.
**Índices:** ninguno más. Sólo se accede por la llave única.

**Cómo se toma un folio sin huecos:** `SELECT ... FOR UPDATE` sobre la fila de la
secuencia **dentro de la transacción del documento**, incrementar, escribir el documento,
confirmar. El folio y el documento nacen o mueren juntos.

**Costo aceptado y declarado:** esto **serializa** la creación de documentos por
`(sucursal, tipo, serie)`. Es intencional —sin huecos es un requisito, no una
preferencia— y a 500–1,000 cuentas al día en el tenant más pesado (§2) el lock se sostiene
sin problema. La alternativa, secuencias con huecos tomadas fuera de transacción, está
descartada por §7.

**Por qué no `AUTO_INCREMENT`:** un autoincremental es global a la tabla y deja huecos en
cuanto una transacción se revierte. La foliación necesita ser por sucursal, por tipo y por
serie, y sin huecos.

---

## 8. Contexto de autorización y middleware

### 8.1 `RequestContext` — objeto inmutable

```
RequestContext (readonly)
├── tenant          Tenant            (siempre)
├── user            User|null         (null en superficies públicas)
├── membership      TenantMembership|null
├── activeRole      Role|null
├── activeBranch    Branch|null
├── terminal        Terminal|null
└── isReadOnly      bool              (derivado del estado del tenant)
```

Se resuelve **una vez por request**, se registra en el contenedor con alcance de request y
se inyecta donde se necesite. `readonly` de PHP: nadie lo muta a media petición. Es la
materialización del contexto completo de ARQUITECTURA_MAESTRA §3.

### 8.2 `ResolveTenantContext` — middleware

Orden de ejecución y reglas:

1. **Autenticación primero.** Sanctum resuelve el usuario (sesión SPA o token).
2. **Origen del tenant** — jamás del cuerpo ni de la query:
   - **SPA web:** llave de sesión `tenant_id`, fijada en el login con selección de tenant (v1, §2).
   - **Token de API:** columnas `tenant_id` y `membership_id` de `personal_access_tokens` (§3.8, D69). Un token pertenece a un tenant y a una membresía.
   - **Superficies públicas:** middleware distinto (§8.5).
3. **Validaciones que abortan el request:** el tenant existe; su estado permite operar (`suspended`/`cancelled` → 403; `read_only` → se marca `isReadOnly` y se rechaza todo método de escritura); existe membresía `active` del usuario en ese tenant.
4. **Contexto de Spatie:** `setPermissionsTeamId($tenant->id)`. Sin esto, Spatie resolvería roles de otro tenant.
5. **Sucursal activa**, en cascada: header `X-Branch` (ULID) → `membership.last_active_branch_id` → la única sucursal del alcance si hay una sola → si no, **422 exigiendo selección**. El header **siempre** se valida contra el alcance de la membresía: viene del cliente, así que no se cree nada.
6. **Rol activo:** header `X-Role` (ULID) validado contra los roles asignados de la membresía → si no viene, `default_role_id` → si tampoco, 403. El cliente sólo puede elegir entre roles que ya tiene, así que la elección es segura; **la suma de roles nunca se usa** (D9).
7. **Terminal:** header `X-Terminal` validado contra las terminales activas de la sucursal activa.
8. **Construir y registrar** el `RequestContext`.
9. **Rechazar `tenant_id` en la entrada:** si el payload trae `tenant_id`, **422**. No se ignora en silencio: un cliente que lo envía está confundido o probando, y ambas cosas se quieren ver.

### 8.3 `TenantScope` — global scope

```
App\Modules\Shared\Domain\Tenancy\TenantScope
```

- Aplica `where {tabla}.tenant_id = {contexto}`.
- **Si no hay tenant en el contexto, lanza excepción.** No devuelve cero filas: un scope que devuelve vacío cuando falta contexto convierte un error de programación en un resultado vacío plausible, y esos son los que llegan a producción.
- Se omite sólo con `withoutTenantScope()`, cuyo uso queda restringido al módulo de super admin y vigilado por test (§10).

**Contexto fuera de HTTP.** Los jobs, comandos y el scheduler no tienen request. Reglas:

- **Todo job serializa su `tenant_id`** y abre contexto explícito antes de tocar el dominio.
- El kernel expone `TenantContext::runFor(int $tenantId, Closure $callback)` que fija el contexto, ejecuta y lo restaura. Único camino permitido.
- Un job que no fije contexto fallará ruidosamente en la primera consulta, por la regla anterior. Eso es lo que se busca.

### 8.4 `Authorize` — servicio de autorización por contexto

```php
Authorize::can(string $permission, ?BranchScope $scope = null): bool
Authorize::authorize(string $permission, ?BranchScope $scope = null): void   // lanza 403
```

Algoritmo:

1. Módulo del permiso activo para el tenant. Si no, **falso** —sin importar el rol—.
2. El **rol activo** tiene el permiso. Se consulta `role_has_permissions` del rol activo, **nunca** la suma de roles del usuario (D9).
3. Si el permiso es de alcance sucursal, la sucursal activa está dentro del alcance de la membresía.
4. Cache en Redis por `(role_id, permission)` con invalidación al editar el rol.

**Prohibido `$user->can()`, `Gate::allows()` y `@can` directos.** Spatie suma roles y aquí
opera el rol activo; usarlo directamente da permisos que el rol activo no tiene. Se vigila
con un test estructural (§10), porque una regla que sólo vive en la revisión de código se
erosiona.

Superficie de uso: un middleware propio `can:{permiso}` sobre las rutas, el servicio en
los servicios de aplicación, y una directiva `v-can` en Vue alimentada por los permisos
del rol activo compartidos en el shell.

### 8.5 `ResolvePublicTenant` — superficies públicas

Para `/m/{slug}` y `/t/{slug}` (Iteración 9): resuelve el tenant por slug, **sin usuario y
sin membresía**, marca el contexto como público y de sólo lectura, y verifica que el módulo
correspondiente esté activo. Grupo de middleware `public`, ya creado en la Fase 0.

### 8.6 Autorización por PIN

Endpoint: `POST /api/v1/authorizations` con `{permission, pin, context}`.

1. Rate limit agresivo por terminal y por IP (D55).
2. Buscar entre las membresías **activas del tenant** una cuyo `pin_hash` coincida. La comparación es contra hash, y el intento fallido incrementa `pin_failed_attempts` de la membresía candidata sólo cuando el PIN identifica a alguien; si no identifica a nadie, se registra el fallo a nivel terminal para no permitir enumerar PIN ajenos.
3. Verificar que esa membresía tiene el permiso solicitado **evaluando la unión de sus roles en el tenant** — no un rol activo, porque quien autoriza no tiene sesión y por tanto no tiene rol activo. Es una **excepción acotada a D9**, aprobada y registrada en [ADR-008](../adr/ADR-008-autorizacion-por-pin-excepcion-rol-activo.md). La unión es de sus roles **en este tenant**, nunca de roles en otros.
4. Emitir una autorización de un solo uso, ligada a la acción y con vida corta (≈2 minutos), que la operación siguiente presenta.
5. Auditar **siempre**, concedida o denegada, con `actor_membership_id` = dueño de la sesión y `authorized_by_membership_id` = dueño del PIN.
6. Al superar `security.pin_max_attempts`, bloquear hasta `pin_locked_until` y auditar `auth.pin_locked`.

La autorización de un solo uso existe para que el permiso no se quede "abierto" en la
terminal después de teclear el PIN: la terminal permanece abierta, la autorización no
(§4.2).

**La excepción de ADR-008 vive aquí y sólo aquí.** El servicio de autorización por PIN es
el único punto del código autorizado a consultar la unión de roles; un test estructural
falla si aparece en cualquier otro lugar (§10). Sin ese candado, la excepción se convertiría
en la regla por goteo.

---

## 9. Eventos emitidos por el kernel

| Evento | Cuándo | Consumidores previstos |
|---|---|---|
| `TenantCreated` | alta de tenant | siembra de roles plantilla, configuración inicial, almacén y sucursal por defecto |
| `TenantStatusChanged` | transición de estado | notificaciones, invalidación de cache |
| `TenantModuleEnabled` / `TenantModuleDisabled` | activación comercial | invalidación de cache de módulos |
| `MembershipCreated` / `MembershipSuspended` | alta y baja de personal | notificaciones; medición de `max_users` |
| `MembershipRolesChanged` | cambio de roles | invalidación de cache de permisos |
| `BranchCreated` | alta de sucursal | creación del almacén por defecto y de la secuencia de folios |
| `SettingChanged` | escritura de configuración | invalidación de cache de configuración |
| `PinAuthorizationGranted` / `PinAuthorizationDenied` | autorización sensible | auditoría, alertas |

Todos síncronos salvo los que sólo producen notificaciones: la regla de §6 es que lo que
afecta la respuesta al usuario es síncrono y la consecuencia es asíncrona.

---

## 10. Pruebas de la iteración

Además de lo que ya exige la definition of done:

- **Aislamiento de tenant del kernel:** crear tenant A con sucursales, almacenes, áreas, terminales, membresías y ajustes; autenticarse en el tenant B; verificar invisibilidad total en cada endpoint.
- **Test estructural de scopes:** endurecer el detector al FQCN exacto de `TenantScope` una vez fijado el namespace, y quitar `App\Models\User` de la lista de excepciones sustituyéndolo por su nuevo FQCN en `Identity`.
- **Test estructural nuevo — `$user->can()` prohibido:** falla si aparece `->can(`, `Gate::allows`, `Gate::authorize`, `@can` o `hasPermissionTo` fuera del módulo `Shared`. Es la única forma de que D9 no se erosione.
- **Test estructural nuevo — la excepción de ADR-008 está acotada:** falla si la consulta de la unión de roles aparece fuera del servicio de autorización por PIN. Sin este candado, la excepción se vuelve la regla por goteo.
- **Test estructural nuevo — `model_has_permissions` vacía:** ningún permiso directo a usuario.
- **Test estructural nuevo — uso de `withoutTenantScope()` restringido** al módulo de super admin.
- **Matriz de autorización** permiso × rol activo × sucursal, sobre los seis roles plantilla.
- **Rol activo, no suma de roles:** usuario con dos roles; verificar que operando bajo el rol sin el permiso recibe 403 aunque el otro rol lo tenga. **Éste es el test que prueba D9.**
- **Invariante I1 (D66), en las dos direcciones:** crear una membresía sin `user_id` y sin perfil de empleado falla; borrar el perfil de empleado de una membresía sin `user_id` falla. Y la resolución de nombre: perfil de empleado gana sobre usuario, `short()` para comanda, `full()` para administración.
- **PIN:** bloqueo por intentos, autorización de un solo uso, que no sirva para otra acción, que no sirva vencida, y que la auditoría registre a los **dos** actores por separado.
- **Token de API (D69):** un token del tenant A no ve nada del tenant B; suspender la membresía invalida el token en la siguiente petición; borrar la membresía borra sus tokens.
- **Inmutabilidad:** `UPDATE` y `DELETE` sobre `audit_entries` y `tenant_status_transitions` lanzan excepción.
- **Configuración:** cascada sucursal → tenant → default; error al escribir llave inexistente; error al sobrescribir en sucursal una llave de `max_scope = tenant`; invalidación de cache al escribir.
- **Foliación:** concurrencia real —dos transacciones simultáneas— sin huecos ni duplicados.
- **CHECK de almacenes:** insertar un `central` con `branch_id` falla en la base, no sólo en la aplicación.

---

## 11. Resumen de tablas

| Tabla | `tenant_id` | ULID | Inmutable |
|---|---|---|---|
| `tenants` | — (§1) | sí | no |
| `tenant_status_transitions` | sí | no | **sí** |
| `subscriptions` | sí | sí | no |
| `tenant_limits` | sí | no | no |
| `tenant_modules` | sí | no | no |
| `users` | — (§1) | sí | no |
| `tenant_memberships` | sí | sí | no |
| `membership_branch_scopes` | sí | no | no |
| `employee_profiles` | sí | sí | no |
| `permissions` | — (§1) | no | no |
| `roles` | sí | sí | no |
| `model_has_roles` | sí | no | no |
| `model_has_permissions` | sí | no | no (debe estar vacía) |
| `role_has_permissions` | — (pivote de catálogo) | no | no |
| `branches` | sí | sí | no |
| `warehouses` | sí | sí | no |
| `preparation_areas` | sí | sí | no |
| `terminals` | sí | sí | no |
| `tenant_settings` | sí | no | no |
| `branch_settings` | sí | no | no |
| `audit_entries` | sí | sí | **sí** |
| `document_sequences` | sí | no | no |
| `personal_access_tokens` | sí (añadido, §3.8) | no | no |

21 tablas nuevas, más las cuatro de Spatie y `personal_access_tokens` modificadas.

---

## 12. Decisiones que los documentos maestros NO cubrían — RESUELTAS

Las quince quedaron decididas el **2026-08-17** y registradas como D66 a D80 en
[`REGISTRO_DECISIONES.md`](../REGISTRO_DECISIONES.md). El detalle del razonamiento y las
alternativas descartadas se conserva abajo, sin editar, porque una decisión sin su
alternativa descartada no se puede reevaluar después.

| | Asunto | Resuelta como | Registro |
|---|---|---|---|
| **P1** | Nombre de una persona sin credenciales | **B** — vive en `employee_profiles`, obligatorio si no hay usuario. **No** fue mi recomendación; ver §3.4.1 y el invariante I1 | D66 |
| **P2** | Rol contra el que se evalúa una autorización por PIN | **B** — unión de roles, acotada a ese endpoint y auditada. **Excepción a D9 → [ADR-008](../adr/ADR-008-autorizacion-por-pin-excepcion-rol-activo.md)** | D67 |
| **P3** | `roles.tenant_id` | **A** — NOT NULL; super admin fuera de Spatie | D68 |
| **P4** | Tenant de un token de API | **A** — columnas en `personal_access_tokens` (§3.8) | D69 |
| **P5** | Alcance por almacén | diferido a la Iteración 3, deuda declarada | D74 |
| **P6** | CURP/RFC/NSS cifrados | en claro, con permiso dedicado y lectura auditada | D77 |
| **P7** | Historial de estados del tenant | tabla propia `tenant_status_transitions` | D75 |
| **P8** | Configuración: una tabla o dos | dos tablas | D78 |
| **P9** | Valor de configuración | una columna de texto tipada por el catálogo | D79 |
| **P10** | Nombre por partes | `paternal_surname` / `maternal_surname` | D76 |
| **P11** | Soft deletes en el kernel | no se usan; ciclo de vida con `status` | D80 |
| **P12** | Estados del tenant | los seis propuestos (§2.2) | D70 |
| **P13** | Roles plantilla | los seis ahora; reparto operativo afinado por iteración | D71 |
| **P14** | Catálogo de permisos | completo desde esta iteración | D72 |
| **P15** | `document_sequences` | en esta iteración | D73 |

### P1 — ¿Dónde vive el nombre de una persona sin credenciales de acceso?

`ESPECIFICACIÓN_MAESTRA` §4.1 exige que exista el empleado sin usuario (el lavaloza en
nómina). Si `tenant_memberships.user_id` es NULL, **no hay de dónde leer su nombre**.

| | A favor | En contra |
|---|---|---|
| **A. `display_name` NOT NULL en la membresía** (recomendada) | Un solo lugar de donde leer el nombre en comandas, tickets y UI; permite nombre distinto por tenant ("Chef Marco" en uno, nombre completo en otro); el POS necesita un nombre corto de todos modos | Duplica el nombre cuando sí hay usuario; hay que decidir si se resincroniza al cambiar el nombre del usuario (propuesta: no, es un dato del tenant) |
| **B. El nombre legal vive en `employee_profiles` y es obligatorio si no hay usuario** | Cero duplicación | Obliga a crear perfil de empleado a quien no lo necesita; la lógica de despliegue se bifurca según haya usuario o no; el nombre legal completo no cabe en una comanda |
| **C. Crear `users` sin contraseña** | Identidad uniforme | Rompe "correo único en el SaaS": el lavaloza no tiene correo. Ensucia la identidad global con no-usuarios |

**Recomendé A. Se decidió B** (D66).

Consecuencias que el diseño absorbe, y que están resueltas en §3.4.1: desaparece
`tenant_memberships.display_name`; el nombre tiene dos orígenes con precedencia explícita
—perfil de empleado sobre usuario—; aparece el invariante **I1** que obliga a que toda
membresía sin credenciales tenga perfil de empleado, impuesto en el servicio de aplicación
y en las dos direcciones; y mostrar un nombre cuesta dos `LEFT JOIN`, resueltos por un
único resolutor del kernel para que ningún módulo escriba su propio `COALESCE`.

Se pierde la capacidad de dar a la misma persona un nombre distinto por tenant sin crearle
perfil de empleado. **No rompe ningún requisito de los documentos maestros** —era una
capacidad que yo propuse, no una pedida—, así que no genera deuda.

---

### P2 — ⚠️ ¿Contra qué rol se evalúa una autorización por PIN?

**Esta es la decisión importante del diseño**, porque roza una regla no negociable.

D9 dice que el permiso efectivo es el del **rol activo**. Pero quien teclea su PIN para
autorizar un descuento **no tiene sesión abierta** y por tanto no tiene rol activo. Hay
que decidir contra qué se evalúa.

| | A favor | En contra |
|---|---|---|
| **A. Contra el `default_role_id` del autorizador** | Fiel a D9 sin excepciones; predecible y explicable | Un gerente cuyo rol por defecto sea otro **recibirá "no autorizado" teniendo el permiso**, delante del cliente. El tenant resolverá eso dándole el rol de gerente a todos, que es peor de lo que se quería evitar |
| **B. Contra la unión de los roles del autorizador, sólo para autorizaciones por PIN, siempre auditado** (recomendada) | Corresponde a la realidad: la autorización es un acto puntual de alguien que no está operando la terminal, para quien "rol activo" no está definido; el control compensatorio —auditar al actor real— es justo el que §6.3 exige | Es una **excepción explícita a D9** y hay que escribirla como tal |
| **C. Exigir que el autorizador elija rol al teclear el PIN** | Sin excepción a D9 | Fricción inaceptable en la operación: dos pantallas para autorizar un descuento en hora pico |

**Recomendé B. Se decidió B** (D67).

Como toca una regla no negociable de `CLAUDE.md`, la excepción quedó redactada, aprobada y
acotada en **[ADR-008](../adr/ADR-008-autorizacion-por-pin-excepcion-rol-activo.md)**, que
no reemplaza ninguna ADR: registra una excepción de alcance limitado a D9. Sus cinco
límites —un solo endpoint, membresía activa del mismo tenant, autorización de un solo uso
ligada a la acción y con expiración, auditoría siempre con los dos actores diferenciados, y
rate limiting con bloqueo por intentos— son parte inseparable de la decisión, no
recomendaciones.

Su puerta de salida está definida y es aditiva: si en operación real los tenants otorgan
capacidad de autorizar con demasiada holgura, se marca con una bandera `roles.can_authorize`
qué roles pueden autorizar y la unión se restringe a ésos. No rediseña nada.

---

### P3 — ¿`roles.tenant_id` NOT NULL o nullable?

Spatie lo crea nullable para permitir roles globales.

| | A favor | En contra |
|---|---|---|
| **A. NOT NULL, y el super admin fuera de Spatie** (recomendada) | Cumple la Regla A sin excepción; imposible crear un rol sin tenant por descuido; el test estructural trata `roles` como cualquier tabla de dominio | El super admin necesita su propio mecanismo: `users.is_super_admin` más su propia capa de autorización, fuera del dominio |
| **B. Nullable, con roles globales para el super admin** | Un solo mecanismo de autorización | Abre la puerta a roles sin tenant en un sistema cuya regla número uno es que todo lleva tenant; el super admin **ya está fuera del dominio** por ADR-002, así que compartir mecanismo es una falsa economía |

**Recomendé A. Se decidió A** (D68).

---

### P4 — ¿Dónde vive el tenant de un token de API?

Para la app Flutter y los agentes de impresión, el tenant no puede venir del cliente
(ADR-002) y no hay sesión.

| | A favor | En contra |
|---|---|---|
| **A. Columnas `tenant_id` y `membership_id` NOT NULL en `personal_access_tokens`** (recomendada) | El tenant viaja con la credencial, no con la petición: imposible de manipular; revocar el acceso de una persona a un tenant es borrar sus tokens de ese tenant; un token no puede cruzar tenants ni por error | Modifica una tabla del paquete; un usuario con dos tenants necesita dos tokens (que es exactamente lo correcto) |
| **B. Habilidades del token (`abilities`) codificando el tenant** | Sin migración | Las habilidades son texto y se comparan como texto; el tenant dejaría de ser una FK verificable. Frágil justo donde no se puede ser frágil |
| **C. Header `X-Tenant` validado contra las membresías** | Un token sirve para todos los tenants | **Viola ADR-002**: el `tenant_id` llegaría del cliente. Descartada por regla |

**Recomendé A. Se decidió A** (D69). Cierra además el pendiente que dejó la Fase 0 sobre
esta tabla. El diseño resultante está en §3.8.

---

### P5 — Alcance por almacén: ¿ahora o en la Iteración 3?

§4.2 menciona alcances de tenant, sucursal **y almacén**. El kernel modela tenant y
sucursal.

**Tomada (D74): diferido a la Iteración 3 (Inventarios)**, con la deuda declarada: hoy
`membership_branch_scopes` da el alcance de sucursal, y el alcance efectivo sobre almacenes
es "los almacenes de mis sucursales". El caso que queda sin resolver es el almacén central,
que no pertenece a ninguna sucursal: hasta la Iteración 3, tocarlo requerirá un permiso
específico en lugar de un alcance. Construir `membership_warehouse_scopes` hoy sería
construir sin consumidor y sin saber qué operaciones de inventario necesitan distinguirlo.

---

### P6 — CURP, RFC y NSS: ¿cifrados en reposo?

§10.4 exige cifrar secretos (credenciales de pasarela, CSD) pero no menciona el PII
laboral, que sí es dato personal sensible bajo la LFPDPPP.

| | A favor | En contra |
|---|---|---|
| **A. En claro, con permiso dedicado y acceso auditado** (recomendada) | El RFC tiene que ser buscable para CFDI y la unicidad por tenant tiene que ser verificable por índice; el permiso `identity.employee_profiles.view_sensitive` más la auditoría de lectura dan control real | Un volcado de base expone el PII |
| **B. Cifrado con cast `encrypted`** | Un volcado no lo expone | Se pierde la unicidad por índice y la búsqueda; habría que añadir *blind indexes*, que es criptografía casera para un beneficio parcial |

**Tomada (D77): opción A**, con la mitigación real puesta donde importa: cifrado del respaldo y del
disco, más permiso y auditoría de lectura. Vale la pena revisarlo al construir nómina, que
es cuando el volumen de PII crece.

---

### P7 — ¿Historial de estados del tenant en tabla propia?

**Tomada (D75): sí** (`tenant_status_transitions`), por el argumento de retención de §2.3:
la bitácora de auditoría se archiva a los 12 meses y una disputa de cobro puede llegar
después. La alternativa es confiarlo a la bitácora y aceptar que el historial comercial
tenga fecha de caducidad.

---

### P8 — Configuración: ¿una tabla con `scope` o dos tablas?

**Tomada (D78): dos tablas** (`tenant_settings`, `branch_settings`). Razón técnica concreta:
en MySQL el índice único no impide duplicados cuando una columna es NULL, así que la tabla
única **no podría garantizar** una sola fila por llave a nivel tenant sin un centinela
`branch_id = 0` —que rompe la FK— o una columna generada. Dos tablas dan unicidad y FKs
verdaderas al precio de una migración casi duplicada.

---

### P9 — Valor de configuración: ¿una columna de texto o columnas tipadas?

**Tomada (D79): una sola columna `VARCHAR(500)`** tipada por el catálogo en código. El
catálogo ya es la autoridad del tipo y valida en la escritura; columnas tipadas darían
tipado en base que nada aprovecha, a cambio de que toda lectura decida de qué columna leer.

---

### P10 — Nombre por partes: ¿`paternal_surname`/`maternal_surname` o `last_name`/`second_last_name`?

**Tomada (D76): `paternal_surname` y `maternal_surname`.** El código va en inglés, pero estos
son conceptos del registro civil mexicano y `second_last_name` invita a llenarlos al
revés. El proyecto es México exclusivamente (§2), así que la precisión gana.

---

### P11 — ¿Soft deletes en el kernel?

**Tomada (D80): no se usan.** Sucursales, almacenes, áreas y terminales se dan de baja con
`status = inactive`, no se borran: tienen documentos históricos apuntándoles y borrarlos
—aun blandamente— dejaría el `deleted_at` conviviendo con índices únicos que ya no
distinguen, y consultas que se olvidan del filtro. `status` es explícito y aparece en el
modelo.

---

## 13. Qué NO entra en esta iteración

Para que el alcance quede cerrado:

- Cajas y sesiones de caja → Iteración 4 (POS).
- Planos y zonas de mesas → Iteración 6 (`Floor`).
- Ruteo a impresoras y emparejamiento de terminal con hardware → Iteración 4 (`Printing`).
- Perfil fiscal del tenant y catálogos del SAT → Iteración 7 (ADR-005).
- Centro de notificaciones → Iteración 8.
- Módulo de super admin: **queda fuera del dominio** y se diseña aparte. Esta iteración sólo deja `users.is_super_admin` y las reglas que lo hacen posible.
- Alcance por almacén → Iteración 3 (P5).
- Traducciones `es_MX` de validación: llegan con los Form Requests de esta iteración.

---

## 14. Orden de implementación

Diseño aprobado. El orden no es arbitrario: cada paso deja verde lo anterior antes de que
algo dependa de él, y los candados estructurales llegan **antes** que el código que deben
vigilar.

1. **Kernel `Shared` primero, sin tablas.** `RequestContext`, `TenantScope` (con la excepción cuando falta contexto), `TenantContext::runFor()`, el trait de tenant y el generador de ULID. Endurecer aquí el test estructural de scopes al FQCN exacto, antes de que exista el primer modelo que deba cumplirlo.
2. **Migraciones de Tenancy e Identity**, incluidas las de Spatie con `tenant_id` NOT NULL, las columnas añadidas de `roles` y `permissions`, y las de `personal_access_tokens`. Mover `App\Models\User` a `Identity` y actualizar la lista de excepciones del test de scopes.
3. **Migraciones de Organization**, con el `CHECK` de almacenes y la FK `branches.default_warehouse_id`.
4. **Configuración y auditoría**, con la inmutabilidad de la bitácora impuesta en el modelo y probada.
5. **`document_sequences`** con su prueba de concurrencia real: dos transacciones simultáneas, sin huecos ni duplicados.
6. **Servicio `Authorize` y middleware de contexto.** Aquí entran los tres tests estructurales nuevos: `$user->can()` prohibido, la excepción de ADR-008 acotada, y `model_has_permissions` vacía.
7. **Autorización por PIN** conforme a ADR-008, con sus cuatro reglas verificadas.
8. **Seeders versionados:** catálogo completo de permisos y los seis roles plantilla.
9. **Tests de aislamiento de tenant del kernel** y la matriz de autorización.
10. **Form Requests con mensajes en español mexicano** y las traducciones `es_MX` que la Fase 0 dejó pendientes.

Lo que hay que traer de la Fase 0 y cerrar en el camino: endurecer el detector de scopes al
FQCN, sustituir `App\Models\User` en la lista de excepciones, y las traducciones `es_MX`.
Están anotados en el registro de decisiones.
