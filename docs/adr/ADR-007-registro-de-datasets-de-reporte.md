# ADR-007 — Cada módulo dueño registra su dataset; el motor de reportes sólo ejecuta

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 7 (Reportes + Dashboards + Notificaciones) |
| **Reemplaza a** | — |
| **Complementa** | ADR-006 (motor declarativo), ADR-001 (monolito modular), ADR-002 (multi-tenant) |

> Resuelve el hueco que ADR-006 dejó abierto: **cómo** el endpoint genérico lee datos que viven en muchos módulos sin romper la encapsulación de ADR-001.

---

## Decisión

El módulo **`Reporting` no consulta las tablas de nadie.** Cada módulo **dueño** de un
dato declara sus **definiciones de reporte** (`ReportDefinition`) y las **registra en un
registro del kernel** (`ReportRegistry`), igual que hoy se registran los *probes* y los
*listeners*. El motor de `Reporting` lee ese registro por un contrato del kernel, y para
cada petición: valida los parámetros contra la definición, **aplica el scoping de tenant
y de sucursal (regla 4 de ADR-006), añade los filtros de la whitelist, las agrupaciones y
las columnas agregadas, y ejecuta**.

La definición **no ejecuta nada ni devuelve resultados**: entrega una **consulta a medio
construir** (un `Builder` de Eloquent sobre la tabla del propio dueño, con sus *joins*
internos ya puestos) más la **whitelist** de columnas/filtros/agrupaciones y el **permiso**.
El motor es quien la termina. Así el scoping se inyecta **una vez, en el motor**, y jamás
depende de lo que declare un módulo.

---

## Contexto

- ADR-006 aprobó un **motor declarativo** con un **endpoint genérico** único, pero no dijo
  **cómo** ese motor accede a `financial_movements` (Finance), `stock_movements` (Inventory),
  `pos_payments`/`pos_discounts`/`pos_order_items` (Pos), `promotion_applications` (Promotions),
  `customer_credit_movements` (Customers), etc.
- ADR-001 §2 regla 5: las tablas de un módulo (`Infrastructure\Models`) son **privadas**; el
  acceso cruzado va por su superficie pública (`Application`, eventos). Regla 3: los efectos
  cruzan **sólo por eventos**; nadie escribe en otro módulo.
- ADR-002: `tenant_id` NOT NULL universal, global scope obligatorio, **prohibido el query
  cross-tenant** en dominio; el `tenant_id` se resuelve del contexto, jamás del cliente.
- `Reporting`/`Dashboards` son capa **analytics** y **sumideros**: nadie depende de ellos.
- Los datos ya están **indexados para reportes** (p. ej. `financial_movements(tenant_id,
  branch_id, occurred_at)`), y hay **servicios de agregación** ya escritos que un reporte debe
  **reusar, no re-derivar** (`Finance\Application\CalculateSessionCut`, `CalculateAvailableTips`).

---

## Problema

`Reporting` necesita **agregar** (GROUP BY, SUM/COUNT/AVG) sobre tablas grandes de otros
módulos. Un `GROUP BY` sobre millones de filas no puede pasar por servicios punto-a-punto por
módulo (los *probes* existen para preguntas **puntuales** y para romper **ciclos**, no para un
motor de agregación genérico). Quedan cuatro caminos, y hay que elegir uno.

---

## Alternativas

**A — Cada dueño registra su dataset; el motor ejecuta. (ELEGIDA)**
El dueño declara la definición (consulta base + whitelist + permiso) y la registra en el
kernel; el motor la termina y ejecuta. Respeta la privacidad de tablas (§2 regla 5): la
consulta base la construye el dueño con **su** modelo; `Reporting` recibe un `Builder`
opaco y nunca nombra el modelo ajeno. Es el mismo patrón de inversión que los *probes*
(el dueño provee, el kernel media, el consumidor consume). Coste: hay que **inventar el
contrato** `ReportDefinition` y la máquina de agregación genérica (no existe; `ListQuery`
sólo lista filas, no agrupa).

**B — `Reporting` declara `depends_on` a todos y lee sus modelos.**
Legal para el candado de fronteras (Reporting es sumidero, no hay ciclo) y hereda el global
scope gratis. Pero lee `Infrastructure\Models` privados de otros módulos y **acopla Reporting
al esquema interno ajeno**: cualquier cambio de columna en Pos o Finance rompe Reporting en
silencio, y la lógica de «qué es una venta» se duplica lejos de su dueño. Rechazada: el
proyecto ha elegido encapsulación sobre atajo en cada decisión previa (D289, D318).

**C — Contratos del kernel estilo *probe*, uno por dato.**
Sirve para verdades ya calculadas (el corte, las propinas) y se **reusa** para ésas, pero una
interfaz fija no expresa `group-by`/filtros arbitrarios: no escala a un motor genérico.

**D — `DB::table()`/SQL crudo desde Reporting.**
Rechazada de plano: salta el global scope de tenant (ADR-002) — la fuga cross-tenant más cara
del producto— y el candado de fronteras.

---

## Cómo funciona (forma del contrato)

- `App\Modules\Shared\Domain\Reporting\ReportDefinition` (kernel, interfaz). Declara:
  `key()` (slug del reporte), `permission()` (un permiso de **dominio ya existente**, p. ej.
  `finance.cuts.view`), `baseQuery()` → un `Builder` de Eloquent sobre la tabla del dueño con
  sus *joins* internos, `branchColumn()` (qué columna sostiene el `branch_id` para el scoping),
  `dimensions()` y `measures()` (columnas disponibles, cada una con su expresión SQL y, en las
  medidas, su agregación SUM/COUNT/AVG), `filters()` (whitelist: clave pública → columna +
  operador permitido + tipo), `groupings()` (agrupaciones permitidas), y `defaults`
  (rango, orden). **No** devuelve resultados.
- `App\Modules\Shared\Domain\Reporting\ReportRegistry` (kernel, singleton): `register()` /
  `all()` / `get(key)`. Cada `*ServiceProvider` de un dueño registra sus definiciones en `boot()`.
- El motor (`Reporting\Application\RunReport`): toma la definición, **inyecta** `where tenant_id`
  (del contexto) y `whereIn(branchColumn, scopedBranchIds del rol activo)`, valida y aplica los
  filtros/agrupaciones/columnas pedidos contra la whitelist (lo no declarado → 422), arma el
  `select` agregado y el `group by`, ordena de forma determinista y pagina.
- **Reuso, no re-derivación:** cuando ya existe un servicio de agregación (corte, propinas), la
  `ReportDefinition` correspondiente delega en él en vez de re-sumar el diario. El motor de
  reportes envuelve ese servicio como cualquier otra definición.

---

## Consecuencias

- `Reporting` **no** declara `depends_on` de dominio: sólo conoce el kernel. Cada dueño gana un
  `depends_on` implícito nulo nuevo (ya conoce el kernel) y una carpeta `Reporting/` con sus
  definiciones. El candado de fronteras sigue verde sin excepciones.
- El scoping de tenant y sucursal se audita **en un solo sitio** (el motor), nunca por reporte
  —la misma razón de seguridad de ADR-006 regla 4—.
- Hay que construir maquinaria nueva de **agregación** (hermana de `ListQuery`, no una
  extensión: `ListQuery` lista filas, esto agrupa y agrega). Se diseña en la Iteración 7.
- Las **definiciones viven en el módulo dueño**, donde está el conocimiento de qué significa el
  dato y dónde ya se planearon los índices (ADR-006: los índices se piensan desde el dataset).
- La **zona horaria** de agrupación «por día» se resuelve en el motor con la TZ de la sucursal
  (columna `branches.timezone`, IANA), no en UTC crudo (ver el diseño de la Iteración 7 para la
  mecánica `CONVERT_TZ` y su dependencia de despliegue).

---

## Reglas vigentes

1. **`Reporting` no consulta modelos de otros módulos.** Lee sólo el `ReportRegistry` del kernel
   y ejecuta los `Builder` que las definiciones le entregan.
2. **La definición nunca aplica el scoping ni ejecuta.** Entrega consulta a medio construir +
   whitelist + permiso; el motor inyecta tenant+sucursal y ejecuta (hereda ADR-006 regla 4).
3. **Cada definición declara un permiso de dominio existente**, no uno inventado; los widgets de
   dashboard lo heredan (ADR-006 regla 3).
4. **Reuso obligatorio** de los servicios de agregación ya escritos (corte, propinas): una
   definición no re-deriva una verdad que ya tiene dueño.
5. **Cero SQL crudo sin scope.** Si una definición usa `selectRaw`/`CONVERT_TZ` por rendimiento,
   el `where tenant_id` y el alcance de sucursal los sigue poniendo el motor sobre el `Builder`,
   y el módulo `Reporting` lleva su propio test de aislamiento de tenant.
