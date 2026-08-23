# Iteración 7 — Revisión de cierre

**Reportes + Tableros + Notificaciones.** Una iteración que no agrega un módulo de negocio sino una **capacidad
transversal**: mirar el negocio. Un motor declarativo de reportes, su exportación y sus tableros con metas, y —al final—
las tuberías para que el sistema **avise** y **entregue** por sí solo: correo por negocio, centro de avisos y reportes
programados.

Este documento no repite el diseño (`ITERACION_7_DISENO.md`) ni el registro de decisiones (D319–D325). Contesta las
mismas cuatro preguntas de siempre.

---

## 1. Qué quedó construido

| Superficie | Lo que resuelve |
|---|---|
| **Motor de reportes** (`/api/v1/reports`, sin pantalla propia) | Un solo endpoint genérico validado contra la definición (ADR-006). El scoping de tenant y de sucursal lo pone **el motor**, no la definición (regla 4): una definición no puede filtrar mal por olvido |
| **Registro de datasets** (kernel) | Cada módulo dueño del dato registra su `ReportDefinition` en el `ReportRegistry` (ADR-007). `Reporting` es sólo el motor: no conoce ningún módulo. Un reporte nuevo se declara donde vive el dato, sin tocar el motor ni el frontend |
| **Catálogo v1** | Ventas por artículo (con **margen** sobre precio neto y costo del momento), antifraude de descuentos/cortesías, y mermas. Registrados por Pos e Inventory |
| **Pantalla de Reportes** (`/admin/reportes`) | Una sola pantalla para **todos** los reportes: se autoconfigura desde `/definition` (filtros, agrupaciones, columnas). Exportar, guardar vistas y **programar** desde aquí |
| **Exportación** (cola `exports`) | PDF (dompdf), Excel/CSV (openspout). Nunca en la petición (regla 5): job idempotente que reconstruye el contexto del autor, escribe el archivo y avisa «export listo». Se consulta y descarga desde «Descargas» |
| **Vistas guardadas** | La configuración de un reporte, personal por membresía, normalizada en filas hijas (sin JSON) |
| **Tableros** (`/admin/tableros`) | Tableros con widgets (número, tabla), cada widget hereda el permiso de su reporte. Borrador/publicado; publicar es un permiso in-code |
| **Metas y semáforo** | Meta por medida, sucursal (o consolidado) y periodo, con dirección más/menos-es-mejor. El semáforo compara el periodo real contra la meta con tolerancia; el consolidado agrega **sólo las sucursales que el rol alcanza** |
| **Correo por negocio** (`/admin/correo`) | Cada negocio configura su SMTP/Gmail; la contraseña se cifra en reposo y nunca vuelve por la API. Correo de prueba que marca la config como verificada (D323) |
| **Centro de avisos** (campana en la SPA) | Avisos por membresía o por rol, consumidos de eventos ya catalogados. Primer productor real: «export listo» y «reporte programado». Marca leídos (D324) |
| **Reportes programados** | «Mándame este reporte cada día/semana/mes a estos correos.» El scheduler encola un job por cada uno que toca; el job corre con el alcance del autor, genera el export, lo envía por el correo del negocio y avisa al autor (D325) |

**Tablas nuevas:** `report_exports`, `saved_report_views` (+ `saved_report_view_params`), `dashboards`,
`dashboard_widgets`, `report_goals`, `tenant_mail_settings`, `notifications`, `scheduled_reports`
(+ `scheduled_report_recipients`). **Columna nueva:** `pos_order_items.unit_cost` (el costo del momento, congelado en la
venta).

Lo que da forma a la iteración es una idea repetida: **leer datos de muchos módulos sin que el lector conozca a ninguno.**
El motor no sabe qué reportes existen —se los dicen; las definiciones no saben aislar —lo hace el motor; el margen del
reporte de ventas se calcula con un costo que **Pos congeló en la venta** pidiéndoselo a Costing por una sonda del kernel,
sin que el reporte cruce dos módulos. La misma inversión que la Iteración 6 estrenó para las promociones, ahora al
servicio de mirar el negocio entero.

---

## 2. Lo que salió mal

### 2.1 Dos candados en rojo que se commitearon con la iteración (el hallazgo más grave)

Las Tandas D1 y D2 se **commitearon con dos candados estructurales en rojo**, y no se supo hasta correr la suite en
**paralelo** al cerrar la D3:

- `KernelTenantIsolationTest` exigía que `tenant_mail_settings` (D1) y `notifications` (D2) —dos tablas nuevas acotadas por
  tenant— entraran en el **barrido de aislamiento** del kernel. No estaban. El barrido dejó de ser completo en el momento
  en que se agregaron los modelos, exactamente el agujero que ese candado existe para tapar.
- `AuthorizationDisciplineTest` marcó el `withoutGlobalScopes()` del scheduler de programados (D3) como consulta
  cross-tenant sin justificar.

Los tres se cerraron: los dos modelos se sumaron al barrido (17 → 19 tablas) con sus constructores, y el comando del
scheduler se justificó en la lista de excepciones **después** de rediseñarlo para que no leyera ningún modelo de dominio
entre negocios (sólo enumera los programados; el job resuelve el rol del autor ya dentro del contexto del tenant).

La lección tiene filo y es de método, no de código: **la suite en paralelo corre candados que la suite normal, corrida a
medias entre tandas, no había ejercido.** D1 y D2 pasaron sus pruebas de tanda pero nadie corrió la suite **completa** —
que es la que incluye el barrido del kernel— antes de commitear. El verde parcial firmó por el verde total. Es la misma
familia de «el verde no vale hasta correr lo que de verdad protege», subida un nivel: no basta con que pasen las pruebas
que escribí para la tanda; tiene que pasar **la red completa**, y en paralelo, antes de cada commit.

### 2.2 Un permiso que miente: `reporting.exports.create`

Al auditar permisos↔rutas para el cierre apareció que `reporting.exports.create` está en el catálogo pero **nada lo
comprueba**. El flujo de exportación quedó controlado —a propósito y bien— por el permiso del **reporte** que se exporta
(ADR-006 regla 3: quien puede ver un reporte puede exportarlo), así que el permiso «crear export» quedó colgando: sugiere
un control que no existe. Es exactamente lo que la Iteración 6 llamó «ningún permiso miente», y éste miente. No se resolvió
en silencio: se deja como **decisión abierta del dueño del producto** en §4 —quitarlo del catálogo (el más limpio, porque
el control real es el del reporte) o convertirlo en una capacidad explícita de exportación (más estricto: ver ≠ exportar)—.
La segunda cambia quién puede exportar, y eso no lo decido yo.

### 2.3 `CONVERT_TZ` devuelve NULL: las zonas con nombre no están cargadas

El motor tenía que convertir un rango de fechas expresado en la hora local de la sucursal a instantes UTC antes de
comparar. La primera versión usó `CONVERT_TZ(col, 'UTC', 'America/Mexico_City')` y **devolvió NULL**: MySQL no trae
cargadas las tablas de zonas con nombre (`mysql.time_zone`), así que toda comparación de rango se volvía falsa y el reporte
salía vacío sin error. La conversión se movió a PHP (se calcula el instante UTC del inicio/fin del día en la zona de la
sucursal y se compara contra la columna UTC). La agrupación **por día** en la zona de la sucursal —que sí necesita SQL— se
difirió a un reporte que la pida (deuda declarada).

### 2.4 El gran total del widget de número que mostraba la primera fila

Un widget de «número» (un solo valor: la venta total del periodo) mostraba **$422.41** —la primera fila del reporte— en
vez de **$850.86** —el gran total—. La causa fue una cadena de dos eslabones: el motor interpretaba `group_by` **ausente**
como «agrupación por omisión» y `group_by` **vacío** como «gran total», pero el cliente de API **omite los parámetros
vacíos** (un filtro vacío no es un filtro), así que `group_by=''` se caía por el camino y el motor agrupaba por omisión.
Se cerró con un centinela explícito (`__total__`) que el cliente sí manda y el motor entiende como gran total. Un valor
vacío y un valor ausente **no son lo mismo**, y el que los borra en el camino no lo sabe.

### 2.5 La foto de la campana: la verificación en navegador confirmó D2 y D3

La suite en verde no cerró la iteración; el navegador sí. La campana de avisos mostró el aviso de prueba, y el ciclo
completo de un reporte programado —crear (201), verlo listado con sus destinatarios, borrar (204)— se ejercitó contra la
API real. Un detalle del entorno headless (devicePixelRatio 1.25) descuadraba las coordenadas de clic, así que el submit
se disparó por el evento real del formulario; el round-trip que importa —la petición al backend y su respuesta— es el
mismo. **No se corrió «correr ahora» desde el navegador a propósito:** si el negocio ya tiene SMTP configurado, dispararía
correos reales a las direcciones de prueba, y enviar correo pide autorización. Esa ruta queda cubierta por la prueba de
integración (`Mail::fake` + export listo + aviso + `last_run_on`).

---

## 3. Las dos preguntas obligatorias de cierre

### 3.1 ¿Qué endpoints no se han llamado nunca en una prueba?

Ninguno. `EveryEndpointIsExercisedTest` en verde dentro de la suite completa (1247 pruebas en paralelo, 5520
aserciones; más 8 del grupo serial). Los cuatro endpoints nuevos de programados (`index`, `store`, `run`, `destroy`)
tienen su prueba de feature, incluida la del comando del scheduler.

### 3.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

De **131** permisos —el mismo total que en la 6, el catálogo sigue cerrado (D10)—, **107** tienen ruta y **24** no. La
cuenta mejoró en cinco respecto a la 6: `dashboards.dashboards.view`, `dashboards.dashboards.manage`,
`dashboards.goals.manage`, `reporting.saved_views.manage` y `reporting.schedules.manage` **ya tienen ruta**.

De los **24** sin ruta:

**Nueve se comprueban DENTRO del código**, que es lo correcto porque su condición sólo se conoce con los datos en la mano:
los tres umbrales de autorización (`finance.expenses`, `inventory.counts`, `inventory.waste`), los tres de descuentos
(`pos.discounts.apply_account/apply_item/courtesy`), `identity.employee_profiles.view_sensitive`,
`pos.credit.charge_to_customer` y —nuevo esta iteración— `dashboards.dashboards.publish` (se verifica al publicar un
tablero).

**Catorce son superficies que aún no existen**, declaradas a propósito: `ecommerce.*` y `digital_menus.*` (Iteración 8/9),
`promotions.coupons.manage` (Iteración 8), `audit.entries.export` (Iteración 11), `notifications.preferences.manage` (las
preferencias de aviso no se construyeron: el centro entrega, pero aún no se configura qué se recibe) y
`tenancy.modules.view` / `tenancy.subscription.view` (el módulo `Tenancy` sigue sin controlador).

**Uno miente:** `reporting.exports.create` (§2.2). Es la única ausencia que no es «se comprueba en código» ni «espera su
iteración», y por eso es una decisión abierta, no una deuda diferida.

---

## 4. Lo que se lleva la Iteración 8

### 4.1 Decisiones pendientes del dueño del producto

| Pregunta | Origen |
|---|---|
| **`reporting.exports.create`: ¿se quita del catálogo o se convierte en capacidad explícita de exportar?** | §2.2, nueva |
| ¿Los cambios de **meta** se historizan? Hoy las metas no están en la lista de inmutables | It.7, diseño §5 |
| ¿Un listado filtrado por una sucursal fuera de alcance responde 403 o devuelve vacío? | D292, sigue abierta |
| ¿La pantalla de comandas necesita marcar «preparado»? ¿`Mesero con cobro` debería poder fiar? | D308, D305 |

### 4.2 Deuda técnica declarada

- **Agrupación por día en la zona de la sucursal**: difirida hasta un reporte que la pida, por el NULL de `CONVERT_TZ`
  (§2.3). Requiere cargar las tablas de zonas de MySQL o resolver el bucketing en PHP.
- **Preferencias de notificación** (`notifications.preferences.manage`): el centro de avisos entrega, pero no se configura
  qué recibe cada quien.
- **El scheduler necesita su cron**: `reports:run-scheduled` está agendado en el scheduler de Laravel; el despliegue debe
  correr `schedule:run` cada minuto (deuda de entorno, no de código).
- **Los avisos aún no tienen todos sus productores**: `StockBajoDetectado` y `CaducidadProxima` se nombran en el diseño
  pero esos eventos todavía no se emiten; el centro está listo para consumirlos cuando existan.
- Deuda arrastrada: las pantallas anteriores al paso 20 de la It.4 siguen pintando fechas en la hora del navegador (D293);
  `assertBranchInScope()` sigue siendo un método estático de un controlador (D292).

### 4.3 Deuda de entorno

`table_definition_cache` sigue en riesgo con las diez tablas nuevas de esta iteración: si la suite topa con SQLSTATE 1615,
súbelo en `my.ini`. Redis sigue sin instalar en desarrollo (las colas corren síncronas o sobre la base).

### 4.4 Lo que la Iteración 8 puede dar por hecho

El negocio se puede **mirar**: cualquier reporte del catálogo se ve, se filtra, se agrupa, se guarda como vista, se
exporta a PDF/Excel/CSV y se arma en tableros con metas y semáforo, todo con el mismo permiso del reporte y el mismo
alcance de sucursal del rol activo. Y el sistema **habla solo**: cada negocio envía con su propio correo, avisa por una
campana interna, y manda reportes programados al cierre de cada periodo sin que nadie apriete un botón.

### 4.5 La lección de esta iteración

**El verde parcial no es el verde.** Dos candados del kernel llevaban dos tandas en rojo y firmaron en verde porque nadie
corrió la red completa —en paralelo— antes de commitear. La red de seguridad de las iteraciones anteriores seguía tensa;
lo que falló fue no pisarla. La regla que deja esta iteración es operativa: **la suite completa en paralelo, más el grupo
serial, corre antes de cada commit de tanda —no sólo las pruebas que escribí para esa tanda—**, porque los candados que
protegen las fronteras del kernel viven fuera de la tanda que las cruza.
