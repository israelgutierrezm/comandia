# Iteración 7 — Diseño: Reportes + Dashboards + Notificaciones

**Estado: APROBADO (2026-08-22).** Aprobadas las cuatro decisiones de kickoff y el plan de la Tanda A; la implementación de
la Tanda A queda autorizada.

Reportes, Dashboards y Notificaciones — la iteración más grande hasta ahora. La arquitectura ya la decidió casi entera:
ADR-006 (motor declarativo, aprobada) y ADR-009 (cómo lee los datos, recién redactada). Este documento la aterriza:
el motor, el contrato de dataset, el catálogo de reportes v1 y las tablas de dashboards/metas/programados/notificaciones.

---

## 1. Decisiones tomadas (kickoff)

| # | Decisión | Consecuencia |
|---|---|---|
| 1 | **Cada módulo dueño registra su dataset**; `Reporting` es sólo el motor (ADR-009) | `Reporting` no depende de ningún módulo de dominio; lee el `ReportRegistry` del kernel |
| 2 | La It.7 se entrega **en cuatro tandas** dentro de la iteración | A: motor + reportes v1 · B: exportación + vistas guardadas + programados · C: dashboards + metas · D: notificaciones |
| 3 | **Margen = utilidad ÷ precio NETO**, con **costo del momento** de la venta | Utilidad = precio_neto − costo_congelado; el IVA no infla el denominador; el costo viejo no distorsiona ventas viejas |
| 4 | **POS congela `unit_cost` al capturar** (lo toma de Costing por una sonda del kernel) | El reporte de margen queda de un solo módulo (Pos), consistente con ADR-009; el margen histórico es fiel para siempre |

Se registrarán como D319 (ADR-009 / mecanismo de datasets), D320 (margen neto + costo del momento), D321 (tandas de la It.7),
D322 (congelar el costo en la venta vía sonda `ProductCostProbe`).

---

## 2. Arquitectura del motor (Tanda A)

### 2.1 El contrato del dataset (kernel)

```
App\Modules\Shared\Domain\Reporting\
  ReportDefinition        (interfaz) — la declara cada módulo dueño
  ReportRegistry          (singleton del kernel) — register() / all() / get(key)
  Dimension, Measure, FilterSpec, Grouping   (value objects de la whitelist)
  ReportResult            (DTO de salida: filas + totales + meta)
```

`ReportDefinition` (lo que declara un dueño, sin ejecutar nada):

- `key(): string` — slug único del reporte (p. ej. `sales.by_day`).
- `permission(): string` — un permiso de **dominio existente** (p. ej. `finance.cuts.view`).
- `baseQuery(): Builder` — `Builder` de Eloquent sobre la tabla **del dueño**, con sus *joins* internos. **No** aplica tenant ni sucursal (eso es del motor).
- `branchColumn(): string` — columna que sostiene el `branch_id` para el scoping (p. ej. `financial_movements.branch_id`).
- `dateColumn(): string` — la columna de fecha del hecho (UTC) para rango y agrupación «por día» (p. ej. `occurred_at`).
- `dimensions(): array<Dimension>` — columnas agrupables (clave pública → expresión SQL). Ej.: `day`, `article`, `category`, `waiter`, `payment_method`.
- `measures(): array<Measure>` — columnas agregadas (clave → expresión + agregación SUM/COUNT/AVG). Ej.: `net_sales` = SUM(...), `tickets` = COUNT(DISTINCT ...).
- `filters(): array<FilterSpec>` — whitelist: clave pública → columna + operador permitido (`eq`/`in`/`range`/`date_range`) + tipo. Lo no declarado → **422**.
- `groupings(): array<string>` — qué dimensiones se permiten como `group by`.
- `defaults` — rango por omisión (p. ej. «hoy»), orden por omisión.

### 2.2 El motor (`Reporting\Application\RunReport`)

Un solo camino de ejecución, auditado una vez:

1. `ReportRegistry::get(key)` o **404** si no existe.
2. `Authorize::authorize(definition.permission())` con el rol activo (**403** si no).
3. Toma `baseQuery()` e **inyecta el scoping** (regla 4 de ADR-006, nunca la definición):
   `->where('tenant_id', contexto.tenantId)` y `->whereIn(branchColumn, membership.scopedBranchIds())`.
4. Valida cada parámetro contra la whitelist (filtros/columnas/agrupaciones/orden); desconocido → **422** (reusa el espíritu de `ListQuery::rejectUnknownFilters`).
5. Traduce ULIDs de filtro (sucursal, artículo, categoría…) a `id` interno **dentro del scope** (un ULID de otra sucursal/tenant → no encontrado, no fuga).
6. Arma `select` de dimensiones + medidas agregadas, `group by` de las dimensiones pedidas, `having`/`order` deterministas (desempate por la primera dimensión).
7. Ejecuta y devuelve `ReportResult` (filas + fila de totales + metadatos de la definición para que el frontend se autoconfigure). Dinero ya redondeado en el servidor (D134); el frontend no re-suma.

**Paginación:** el endpoint genérico usa **cursor** (alto volumen, como el diario), salvo reportes ya agregados y acotados (por día/mes) que caben en una página.

### 2.3 Zona horaria «del día» (mecánica)

`occurred_at` es UTC; agrupar «por día» debe usar la TZ de la **sucursal** (§7, crítico). Un reporte multi-sucursal
mezcla zonas en una sola consulta, así que el bucket por día se hace **por fila** con `CONVERT_TZ(occurred_at, '+00:00',
branches.timezone)` (IANA, seguro ante horario de verano). **Dependencia de despliegue:** MySQL necesita las **tablas de
zonas horarias nombradas** cargadas (`mysql_tzinfo_to_sql` / `tables` de `mysql`); en WampServer hay que cargarlas una
vez. Se documenta en `ENTORNO_LOCAL.md`. Alternativa descartada: un *offset* numérico fijo (erróneo medio año por DST) o
precomputar una columna `business_date` al escribir (sería un agregado materializado, prohibido en v1).

Para el **filtro** de rango (from/to que el usuario expresa en hora local de su sucursal), el motor convierte los límites
a UTC antes de comparar contra `occurred_at`, para que «hasta el 5» no corte a las 18:00 del 4.

### 2.4 El endpoint genérico

```
GET  /api/v1/reports/{report}            → ejecuta la definición con los parámetros de query (validados)
GET  /api/v1/reports/{report}/definition → devuelve la definición (columnas/filtros/agrupaciones) para autoconfigurar el frontend
GET  /api/v1/reports                      → lista los reportes que el rol activo puede ver (para el menú)
```

El permiso lo aplica el motor por la definición; `GET /reports` filtra el catálogo por `Authorize::allows(permission)`.

---

## 3. Catálogo de reportes v1 (Tanda A)

Cada reporte lo **registra su módulo dueño**. Todos heredan el scoping tenant+sucursal del motor. Índices: los datasets
leen tablas **ya indexadas para reportes**; donde falte, se justifica el índice nuevo aquí.

| Reporte (`key`) | Dueño | Permiso | Dimensiones | Medidas | Índice base |
|---|---|---|---|---|---|
| `finance.cut` (corte/arqueo) | Finance | `finance.cuts.view` | método de pago | esperado, declarado, diferencia | **reusa** `CalculateSessionCut` (no re-deriva) |
| `sales.by_day` | Finance | `finance.journal.view`* | día (TZ sucursal), sucursal | ventas netas, tickets, ticket promedio | `financial_movements(tenant,branch,occurred_at)` ✓ |
| `sales.by_article` | Pos | `pos.orders.create`† | artículo, categoría | unidades, venta neta, **margen** | `pos_order_items(tenant,account,status)` (+ eval. índice por artículo) |
| `antifraud.by_actor` (robo hormiga) | Pos | `audit.entries.view` | actor (autorizador), tipo (descuento/cortesía/promoción/cancelación) | conteo, monto | `pos_discounts(tenant,authorized_by,created_at)` ✓ ; `promotion_applications(tenant,promotion_id,applied_at)` ✓ |
| `inventory.waste` (mermas) | Inventory | `inventory.waste.create`† | motivo, artículo, almacén | cantidad, costo | `stock_movements(tenant,kind,occurred_at)` ✓ |
| `inventory.value` (valor de inventario) | Inventory | `inventory.stock.view` | almacén, categoría | saldo, **valor = saldo × costo vigente** | `article_stocks` (proyección) + `article_costs` |
| `finance.tips` (propinas por persona) | Finance | `finance.cuts.view` | persona (membresía) | asentado, liquidado, **disponible** | **reusa** `CalculateAvailableTips` |
| `customers.credit` (cartera de crédito) | Customers | `finance.customer_credit.view` | cliente | saldo, límite, disponible | `customer_credits` (proyección) |

\* `finance.journal.view` existe (el diario). † Se evaluará al diseñar cada dataset si el permiso de «crear/operar» es el
correcto para «ver el reporte de», o si conviene un permiso de lectura ya existente; ninguno se inventa (catálogo cerrado).

**El margen** (`sales.by_article`) se calcula en el servidor: `utilidad = Σ(precio_neto − costo_del_momento) × cantidad`,
`margen = utilidad ÷ Σ(precio_neto × cantidad)`. Precio neto = `unit_price` congelado ÷ (1 + `vat_rate`) (los precios son
IVA-incluido, D30). El **costo del momento** se congela en `pos_order_items.unit_cost` al capturar (D322): POS lo pide a
Costing por una sonda del kernel `ProductCostProbe` (Costing la implementa; Pos la consume — dependencia acíclica, patrón
de los tres probes existentes). Un artículo sin costo aún (nunca comprado) congela `0` y el margen lo refleja como tal.
Así el reporte de margen lee **sólo** tablas de Pos y respeta ADR-009.

---

## 4. Tanda B — Exportación + vistas guardadas + programados (esbozo)

- **Exportación** por la cola `exports` (nunca en la petición; ADR-006 regla 5). Job idempotente (llave por
  usuario+reporte+parámetros+formato), `TenantContext::runFor()` en el worker (patrón `DeductSoldItems`), notificación
  «export listo» al terminar. **Dependencia nueva a aprobar:** librería PDF (`dompdf`) y Excel/CSV (`openspout`, ligera).
  Entrega por **URL firmada temporal** (archivo fuera del webroot, TTL), no descarga directa. `retry_after` de `exports`
  se sube (hoy 90 s es corto — deuda ya anotada).
- **Vistas guardadas** (`saved_report_views`): por **membresía** (usuario en tenant). Sin JSON: los parámetros guardados
  se normalizan en filas hijas tipadas (`saved_report_view_filters`: view_id, clave, operador, valor) + columnas para
  orden/agrupación/columnas elegidas. Permiso `reporting.saved_views.manage`.
- **Reportes programados** (`scheduled_reports`): reporte + parámetros + frecuencia (cron acotado) + formato +
  destinatarios (normalizados en tabla hija). El scheduler dispara en la TZ de la sucursal; corre con el permiso/alcance
  del que lo programó (contexto restablecido en el worker). Permiso `reporting.schedules.manage`.

## 5. Tanda C — Dashboards + metas (esbozo)

- `dashboards` (tenant, dueño membresía, nombre, estado borrador/publicado, audiencia rol/sucursal/consolidado),
  `dashboard_widgets` (dashboard_id, report_key, tipo de visualización, rango, posición en grid — todo en columnas
  tipadas, **sin JSON**). El widget **hereda** el permiso del reporte (ADR-006 regla 3): no se pinta si el rol no lo tiene.
- **Metas** (`report_goals`): tenant, report_key, measure (qué medida), sucursal (o consolidado), periodo
  (día/semana/mes), valor DECIMAL, dirección (más-es-mejor / menos-es-mejor). El semáforo compara real vs meta con
  umbrales configurables (toggle jerárquico, precedente `pricing.stale_price_tolerance_percent`). Permiso
  `dashboards.goals.manage`. **A decidir:** si los cambios de meta se historizan (hoy las metas **no** están en la lista
  de inmutables).
- **Consolidado** = agrega **las sucursales que el rol activo alcanza** (un rol con todas las sucursales ve todas; uno
  acotado ve sólo las suyas). No relaja el scoping.

## 6. Tanda D — Notificaciones (esbozo)

Centro de notificaciones por usuario/rol que **consume eventos de dominio ya catalogados** (no inventa fuentes):
`StockBajoDetectado`, `CaducidadProxima`, diferencia de corte, transferencia pendiente, «export listo», «reporte
programado». Tabla `notifications` (tenant, destinatario membresía/rol, tipo, entidad ulid, leído_en) — sin JSON, con
columnas tipadas por tipo de aviso. Se delimita qué ya vive en el kernel vs qué construye la 7.

---

## 7. Plan de implementación de la Tanda A (pasos)

Decisiones de kickoff resueltas (§1); catálogo v1 (§3) aprobado con los ocho reportes. Orden de construcción propuesto,
cada paso con su prueba antes del commit:

1. **Sonda `ProductCostProbe`** (kernel) + implementación en Costing (`article_costs` → costo vigente por artículo) +
   binding; null-object devuelve `0`. Prueba: la sonda resuelve al proveedor real; sin Costing, `0`.
2. **Congelar el costo en la venta:** migración `pos_order_items.unit_cost DECIMAL(12,4)`; `CaptureOrderItems` lo llena
   desde la sonda al capturar (junto a precio/IVA). Prueba: una línea capturada congela el costo vigente; un cambio de
   costo posterior no la altera.
3. **Contrato del motor** (kernel): `ReportDefinition`, `ReportRegistry`, `Dimension/Measure/FilterSpec/Grouping`,
   `ReportResult`. Sin dependencias de dominio.
4. **Máquina de agregación** (`Reporting\Application\RunReport` + `AggregateQuery`): scoping tenant+sucursal, validación
   de whitelist (422), `CONVERT_TZ` por día en TZ de sucursal, agregados, orden determinista, cursor. Prueba de
   **aislamiento de tenant del módulo Reporting** (crítica: usa `selectRaw`/`CONVERT_TZ`).
5. **Endpoint genérico** `GET /reports`, `/reports/{report}`, `/reports/{report}/definition` + Form Request de
   parámetros + Resource de salida.
6. **Las ocho definiciones**, cada una registrada por su módulo dueño (§3), con sus índices justificados y su permiso.
   El corte y las propinas **reusan** los servicios de Finanzas; el antifraude cruza Pos+Promotions por dos definiciones
   compuestas en el frontend (o una definición Pos que lea sólo lo suyo). Prueba por reporte: datos correctos + permiso.
7. **Frontend:** pantalla genérica de reporte que se autoconfigura desde `/definition` (filtros + columnas + agrupación),
   y una entrada de menú «Reportes». Verificación en navegador con **dos sucursales** y rango cruzando medianoche.

Al terminar la Tanda A: commit, revisión parcial, y arranca la Tanda B (exportación + vistas + programados), que pedirá
aprobar la dependencia nueva de librería PDF/Excel.

---

## 8. Definition of Done (aplica a cada tanda)

Tests unit de dominio + feature de API + **test de aislamiento de tenant del módulo `Reporting`** (crítico: el motor
puede usar `selectRaw`/`CONVERT_TZ`) + tests de autorización por reporte + idempotencia de jobs (exports, programados) +
migraciones con índices justificados + Form Requests para toda entrada + Resources para toda salida + whitelist de
filtros + eventos documentados + sin lógica de negocio en Vue + verificación en navegador con **dos sucursales** y rango
de fechas cruzando medianoche (para atrapar errores de TZ).
