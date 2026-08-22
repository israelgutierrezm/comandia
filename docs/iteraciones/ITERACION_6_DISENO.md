# Iteración 6 — Promociones + Clientes/CFDI-ready

**Estado:** **APROBADO** el 2026-08-21. Las cuatro decisiones de §8 quedaron aprobadas tal como se recomendaron:
el motor vive en `Promotions` y el POS lo consume por el probe `PromotionResolver` (D310); los cupones se difieren a la
Iteración 8 (D314); la promoción tiene su propio `FinancialMovementType::Promotion` (D313); y el snapshot fiscal se
congela en el ticket facturable al cobrar (D317). Las decisiones de producto se resolvieron por el default recomendado:
validación CFDI hasta régimen↔persona con la matriz régimen↔uso diferida al timbrado (D316), y la excepción de no
acumulables como toggle por tenant/sucursal (D315). El permiso de abono del cajero **no** se cambia sin decisión
explícita: queda abierto.

**Alcance de la hoja de ruta:** promociones POS (catálogo acotado, D50) y el expediente del cliente con captura fiscal
CFDI-ready sin timbrado (D42, D43, ADR-005).

---

## 0. Una corrección de numeración, antes de nada

La lista numerada de §14 de la Arquitectura quedó **desactualizada** cuando la Iteración 4 absorbió Finanzas (D235): la
tabla de estado ya renumeró a diez iteraciones, pero la lista sigue enumerando once. Varios documentos y el propio
scaffold heredan la numeración vieja y llaman a este trabajo «la 7» (`app/Modules/Promotions/.module.md`,
`ITERACION_4_DISENO` §11/§15, D235, `ITERACION_5_REVISION` §3.2).

**La verdad autoritativa** (tabla de estado §14 + `ITERACION_5_REVISION` §4 «lo que se lleva la Iteración 6»):

| # | Iteración |
|---|---|
| 6 | **Promociones + Clientes/CFDI-ready** ← esta |
| 7 | Reportes + Dashboards + Notificaciones |
| 8 | Menús digitales + E-commerce + pasarelas |
| 9 | App Flutter |
| 10 | Endurecimiento |

Reconciliar esas referencias es el **paso 0** de la implementación.

---

## 1. Qué existe ya, y qué es nuevo

**Promociones: nada.** `app/Modules/Promotions/` es una carpeta con un solo `.module.md` que la declara «reservada, sin
código» (capa `operations`, `depends_on: []`). Sus tres permisos —`promotions.promotions.view/manage`,
`promotions.coupons.manage`— están en el catálogo cerrado desde la Iteración 1 (D72), sin ruta.

**Clientes: el mínimo.** La Iteración 4 construyó `customers`, `customer_credits` y `customer_credit_movements` —lo que
el cobro y el crédito necesitan (D235)— y difirió **explícitamente** el expediente: perfiles fiscales, direcciones y
cumpleaños. Los permisos `customers.fiscal_profiles.manage` y `customers.addresses.manage` esperan sin ruta. Esta
iteración **extiende** el módulo, no lo recrea.

**El gancho ya estaba puesto.** `pos_discounts.authorized_by_membership_id` es nullable con este comentario textual:
*«el día que exista un descuento automático —una promoción— no habrá humano autorizando»*. La Iteración 4 anticipó
exactamente esto.

---

## 2. Las cinco preguntas que definen la iteración

### 2.1 ¿Quién decide qué promoción aplica, y cómo lo consume el POS sin cerrar un ciclo?

Es la pregunta arquitectónica central, y tiene una respuesta que el proyecto ya sabe dar.

Una promoción **cambia lo que el cliente paga**, y el total tiene que reflejarlo **en la misma petición** —al
previsualizar la cuenta y al cobrar—. Eso la convierte en una **pregunta** («¿qué promoción aplica a estas líneas, a
esta hora, en esta sucursal?»), no en un anuncio de algo que ya pasó. Y las preguntas que cruzan módulos viajan por una
**interfaz del kernel**, no por un evento (D239, D266, D277): *«un evento no sirve para preguntar; la respuesta hace
falta antes de escribir»*.

**Propuesta:** un contrato del kernel `PromotionResolver` que `Promotions` implementa y el POS invoca.

```
App\Modules\Shared\Domain\Contracts\PromotionResolver
    resolveForAccount(int $branchId, string $atIso, LineSnapshot[] $lines): PromotionOutcome
```

`LineSnapshot` y `PromotionOutcome` son DTOs inmutables de **primitivos** del kernel (article_id, category_id, quantity,
unit_price de entrada; promotion_ulid, alcance, resulting_amount de salida). `Promotions` lee **sólo** su propio catálogo
más el snapshot que recibe; **nunca** toca `pos_order_items`. El POS pasa primitivos y recibe primitivos.

**Por qué esto no cierra un ciclo:** el POS depende del **contrato del kernel**, y `Promotions` **implementa** el
contrato del kernel. Ninguno se declara en el `depends_on` del otro. `config/comandia.php` se queda con
`Promotions.depends_on = []` y el `Pos.depends_on` **sin cambios**. Es el mismo patrón que `LiveServiceProbe`
(`Floor`↔`Pos`) y `CashSessionProbe` (`Customers`↔`Pos`) — sólo que aquí el POS es el **consumidor** (pregunta) y
`Promotions` el **proveedor** (implementa). Dirección limpia, un solo sentido: `Pos → interfaz ← Promotions`.

**Por qué NO en el POS:** si el POS leyera el catálogo de promociones y calculara «mejor gana», tendría una dependencia
lateral `operations→operations` que la regla 2 de §2 prohíbe, y esparciría el catálogo acotado (D50) y la regla de no
acumulables por el punto de venta. **El POS no debe conocer ni un tipo de promoción.**

**Degradación:** como el POS nunca se bloquea (§6, CLAUDE.md), si el binding del resolver falta se cae a un
**null-object** que devuelve «ninguna promoción». A diferencia de `LiveServiceProbe`, que falla ruidoso a propósito,
aquí el default seguro es «sin promoción»: es preferible cobrar sin la promoción que no poder cobrar.

### 2.2 Probe para DECIDIR, evento para REGISTRAR

§6.3 exige un **«registro por venta de promoción aplicada y monto descontado»**. Ese registro es un efecto que **sí
puede llegar tarde** —es analítica, no cobro—, así que va por **evento**, no dentro del probe. Escribir dentro de una
«pregunta» contaminaría el probe y ataría su tiempo a la transacción del POS.

El flujo completo, entonces:

1. El POS **pregunta** al `PromotionResolver` (probe) y recibe la promoción ganadora y su monto.
2. El POS **escribe el efecto** reutilizando su propia maquinaria: una fila en `pos_discounts` marcada como automática
   (`source = promotion`, `authorized_by_membership_id = null` — el gancho ya previsto), y `CaptureOrderItems::recalculate()`
   sigue siendo la **única** vía de cálculo del total. Así la aritmética del IVA-incluido vive en un solo sitio y las
   promociones no la reinventan.
3. En `afterCommit`, el POS **emite** un evento del kernel con primitivos, incluyendo el `promotion_ulid`.
4. `Promotions` **escucha** ese evento y escribe su registro «promoción aplicada por venta» (§6.3).
5. `Finance` ya escucha para asentar el diario en negativo — el mismo camino que un descuento manual.

Es probe para la decisión y evento para el registro: mapea 1:1 sobre las dos viñetas de la regla 3 de §2 que el código
ya tiene codificadas (D266/D277 + D231).

### 2.3 ¿La promoción-aplicada es inmutable?

**Sí.** No está en la lista de §7 ni en `ImmutableTablesTest`, así que hay que **decidirlo** — y el precedente es
contundente: es el hermano automático de `pos_discounts`, que ya es append-only y vive en la «zona de máxima auditoría»
(§6.3). Los dos registran dinero retirado de una venta y los dos alimentan el reporte antifraude (§9). Se modela igual:
escrita una vez al momento de la venta, con el monto calculado en el **servidor**, sin UPDATE/DELETE, corrección por
reversa. Al hacerla `Immutable`, `ImmutableTablesTest` **obliga** a declararla en §7 de la Arquitectura y en el test —
el mismo mecanismo bidireccional por el que el kardex «pidió su candado él mismo».

### 2.4 ¿Las definiciones de promoción se editan o se versionan?

El proyecto historiza precios y costos porque «¿me subió?» exige dos observaciones del mismo dato. Una **definición** de
promoción no tiene esa exigencia **si el registro aplicado congela su resultado** — que es lo que hace (2.3). Así que:

**Propuesta:** las definiciones se **editan en sitio**, sin tabla de historial. Llevan una columna `version` para
concurrencia optimista (como `floor_plans` en la Iteración 5), no para historizar. Es el patrón consolidado «congelar el
resultado al aplicar» (D233 la propina, D286/D295 la bandera del cajón): cambiar la definición hoy no altera lo que
descontó ayer, porque el registro aplicado no la re-lee.

### 2.5 ¿La captura CFDI dónde termina, exactamente?

ADR-005 traza una línea dura entre la **calidad del dato** (barata ahora, carísima después) y la **emisión** (cara ahora,
igual de cara después). v1 hace lo primero:

**v1 SÍ** — captura y **valida contra catálogos oficiales**: RFC (persona física/moral), razón social a la letra del
SAT, CP fiscal, régimen fiscal (`c_RegimenFiscal`), uso CFDI (`c_UsoCFDI`); 0..N perfiles fiscales y 0..N direcciones
por cliente; ticket con folio facturable (que **ya existe** — `pos_tickets` tipo `final_receipt`).

**v1 NO** — no timbra ante un PAC, no custodia CSD, no cancela con acuse, no emite complementos de pago ni factura
global, no produce XML/PDF sellado. Es «la primera gran evolución», y el modelo se diseña **pensando en el timbrado**
(ADR-005 regla 4) para que se conecte sin rediseñar el dominio de clientes.

**Un hallazgo:** la «forma/método de pago del SAT» (`c_FormaPago`, PUE/PPD) **no** está en la lista de captura de ADR-005
ni de §6.6 —que nombran sólo RFC, razón social, CP, régimen y uso—. Es un dato **por documento**, no del perfil fiscal, y
pertenece al timbrado. Queda **fuera de la captura de v1**, documentado como frontera.

---

## 3. Parte A — Promociones (módulo nuevo)

### 3.1 Alcance: tres tipos POS ahora; los cupones esperan al e-commerce

Los cuatro tipos de §6.3 son: (a) descuento %/monto por categoría o artículos en horario (happy hour); (b) 2x1/NxM;
(c) precio especial por ventana; (d) **cupones e-commerce**. Pero §6.8 (línea 166) marca los cupones como **alcance
exclusivo del módulo E-commerce**, que es la Iteración 8.

**Propuesta:** esta iteración construye el **motor completo** y los **tres tipos POS** (a, b, c). Los **cupones se
difieren** a la Iteración 8: un cupón es un canal de canje (con código, en el checkout de la tienda) sobre el mismo
motor, y construir su redención sin tienda no le sirve a nadie. `promotions.coupons.manage` sigue sin ruta.

### 3.2 Esquema

| Tabla | Qué es |
|---|---|
| `promotions` | La definición: `tenant_id`, `ulid`, `name`, `type` enum(`percentage`, `amount`, `nxm`, `special_price`), campos de valor según tipo (ver abajo), ventana (`starts_on`/`ends_on` fecha, `daily_start`/`daily_end` hora, `weekdays` — ver 3.3), `priority` smallint, `is_stackable` bool, `status`, `version`, timestamps |
| `promotion_targets` | A qué aplica, **sin JSON**: `tenant_id`, `promotion_id`, `article_id` nullable, `article_category_id` nullable (exactamente uno no nulo, CHECK). Una promoción con N artículos/categorías tiene N filas |
| `promotion_branches` | Dónde aplica: `tenant_id`, `promotion_id`, `branch_id`. Sin filas = todas las sucursales (o una bandera `all_branches` en `promotions` — decisión menor de diseño) |
| `promotion_applications` | **Append-only.** El registro por venta (§6.3): `tenant_id`, `ulid`, `promotion_id`, `pos_account_ulid` char(26) **sin FK** (cruza a Pos: se referencia por ULID como en los eventos, D231/D151), `pos_order_item_ulid` nullable, `amount_discounted` DECIMAL(12,2), `applied_at`. Inmutable |

Los campos de valor por tipo (columnas nullable, no JSON): `percent_value` (para `percentage`), `amount_value` (para
`amount` y `special_price` — el precio especial es el precio final de la línea), `buy_quantity`/`pay_quantity` (para
`nxm`: 2x1 es buy=2 pay=1; 3x2 es buy=3 pay=2).

**Dinero:** DECIMAL(12,2) (§7). **Índices:** todos inician por `tenant_id`; el del resolver es
`(tenant_id, status, starts_on, ends_on)` para traer las vigentes de un golpe; `promotion_applications` lleva
`(tenant_id, promotion_id, applied_at)` para el reporte por promoción. Cada índice justificado en la migración.

### 3.3 La ventana horaria, en la zona de la SUCURSAL

Happy hour «de 6 a 8 de la tarde los jueves» es hora **local de la sucursal**, no UTC (§7). Los timestamps se guardan en
UTC pero la ventana se evalúa en la zona de la sucursal —el mismo dato `branch.timezone` que la Iteración 5 por fin
consumió (D293)—. `weekdays` se modela como columnas booleanas por día o un smallint de banderas (7 bits); **no JSON**.

### 3.4 «Mejor gana, excepción configurable»

**El algoritmo:** cuando varias promociones aplican a la misma línea o cuenta, el resolver calcula el monto descontado
de cada una sobre la base viva y elige la de **mayor descuento** para el cliente. Empate → mayor `priority` → menor
`ulid` (determinista y reproducible).

**La excepción configurable:** es un toggle del sistema jerárquico de configuración (D20 — nunca una columna suelta):
`promotions.allow_stacking` por tenant/sucursal. Con él encendido, las promociones marcadas `is_stackable` sí se acumulan
entre sí. Apagado (el default), gana una.

**Manual vs promoción:** un descuento **manual** (con PIN) sí puede aplicarse encima de una promoción automática —son
dos actos de naturaleza distinta, y bloquearlo ataría las manos del gerente—. Lo que no se acumula por defecto es
promoción sobre promoción.

### 3.5 El asiento financiero

Una promoción aplicada es dinero que se dejó de cobrar, igual que un descuento. Se asienta **exactamente como el
descuento manual** —mismo camino, `Finance` escuchando el evento—. La única decisión: ¿su propio
`FinancialMovementType::Promotion`, o reusa `Discount`? El reporte antifraude de §9 quiere **separar** el descuento
manual (sospechoso: alguien lo autorizó) de la promoción automática (una regla del negocio). **Propuesta: tipo propio
`Promotion`** (signo −1, exige sesión), para que el corte y los reportes los distingan sin adivinar. ADR-004 pide
«tipo + origen»: esto lo respeta.

### 3.6 ¿PIN?

**No.** Una promoción automática no tiene autorizador humano: la autorización ocurrió cuando alguien con
`promotions.promotions.manage` **creó** la promoción. Aplicarla es una regla, no un acto sensible. (Un cupón sí podría
pedir su código como «prueba de posesión», pero los cupones son de la Iteración 8.)

---

## 4. Parte B — Clientes/CFDI-ready (extiende el módulo existente)

### 4.1 Esquema nuevo

| Tabla | Qué es |
|---|---|
| `customer_fiscal_profiles` | 0..N por cliente. `tenant_id`, `ulid`, `customer_id`, `rfc` (validado por **forma**, no validez fiscal, el precedente exacto de proveedores: `/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/`, mayúsculas, vacío→null), `business_name` (razón social), `person_type` enum(`fisica`, `moral`) derivado de la longitud del RFC, `postal_code` (CP fiscal), `tax_regime_code` (FK a catálogo), `cfdi_use_code` (FK a catálogo), `is_default` (con centinela para «uno solo predeterminado», el patrón D78), `status`, timestamps |
| `customer_addresses` | 0..N por cliente. **Sin JSON — columnas**: `label`, `street`, `exterior_number`, `interior_number` nullable, `neighborhood` (colonia), `municipality` (municipio/delegación), `state`, `postal_code`, `country` fijo `MX`, `reference` nullable, `is_default` (mismo centinela) |
| `sat_tax_regimes` | Catálogo oficial `c_RegimenFiscal`: `code`, `description`, `applies_to_fisica` bool, `applies_to_moral` bool. Sembrado por seeder versionado (como los permisos) |
| `sat_cfdi_uses` | Catálogo oficial `c_UsoCFDI`: `code`, `description`. Sembrado |

**Cumpleaños:** columna `birthday` DATE nullable en `customers` (D43). Las consultas «cumpleañeros del mes» filtran por
`MONTH(birthday)`. Guardar la fecha completa (no sólo mes/día) porque cuesta lo mismo y habilita más adelante la
felicitación por edad; quien no la sepa deja null.

**Catálogos, ¿tablas o enum en código?** Tablas sembradas, no enum. ADR-005 regla 1 prohíbe texto libre, y un catálogo
del SAT tiene cientos de entradas que además **cambian** —una tabla versionada se actualiza con un seeder sin tocar
código, igual que `comandia:permissions:sync`—.

### 4.2 La validación cruzada régimen ↔ uso ↔ tipo de persona

El SAT restringe qué régimen es válido para persona física vs moral, y qué usos CFDI admite cada régimen. **Propuesta
para v1:** validar la **pertenencia** a cada catálogo y la compatibilidad **régimen ↔ tipo de persona** (que el catálogo
ya trae con `applies_to_fisica`/`applies_to_moral`). La matriz completa **régimen ↔ uso** se **documenta como deuda** y
se cierra al construir el timbrado: es una madriguera de reglas del SAT que cambian, y un uso incompatible se descubre al
facturar —el mismo criterio con el que el RFC valida forma y no dígito verificador—.

### 4.3 El historial del cliente

«Consumos, crédito, pedidos e-commerce, cumpleaños, notas» (§6.6) es una **vista agregada**, no una tabla nueva: los
consumos salen de `pos_accounts.customer_id`, el crédito de `customer_credit_movements` (que ya tiene su estado de
cuenta), los pedidos e-commerce llegarán en la Iteración 8. Un endpoint `GET /customers/{customer}/history` que compone
lo que existe. Nada que materializar.

### 4.4 El folio facturable y la asociación de perfil fiscal

El ticket ya folia (`final_receipt`). Lo que falta para «pedir factura después» es saber **qué perfil fiscal** eligió el
cliente en esa venta. **Propuesta:** al cobrar, si la cuenta tiene cliente, se puede **elegir uno de sus perfiles
fiscales**, y el ticket **congela** ese snapshot fiscal (RFC, razón social, régimen, uso, CP — congelado como todo en el
POS, D233). Sin cliente o sin selección, el ticket es «público en general», que es el caso normal.

**Es un toque pequeño al POS** (una columna/tabla de snapshot fiscal en el ticket y un campo opcional en el cobro), y lo
marco como **decisión**: incluirlo ahora deja el CFDI-ready realmente listo; diferirlo mantiene la iteración enteramente
en `Customers`/`Promotions` y deja el enganche fiscal↔venta para el timbrado.

### 4.5 Las rutas que faltan

Se conectan `customers.fiscal_profiles.manage` y `customers.addresses.manage` a sus controladores nuevos — los permisos
que llevan dos iteraciones declarados sin proteger nada.

---

## 5. Lo que se queda fuera, y por qué

| Fuera | Por qué |
|---|---|
| **Timbrado CFDI** (PAC, CSD, cancelaciones, complementos, factura global, XML sellado) | ADR-005: «primera gran evolución». v1 es captura + validación + folio |
| **Cupones** | §6.8: exclusivos de E-commerce (Iteración 8). El motor sí se construye; el canje espera a la tienda |
| **Lealtad / puntos** | D44: fuera de v1 |
| **Tipos de promoción fuera del catálogo acotado** | D50 + §8: «tipos adicionales de promoción» son evolución post-v1 |
| **`c_FormaPago` / método PUE-PPD** | Dato por documento, no del perfil fiscal; pertenece al timbrado (§2.5) |
| **Matriz completa régimen ↔ uso CFDI del SAT** | Se valida pertenencia y régimen↔persona; la matriz fina se cierra con el timbrado (§4.2) |

---

## 6. Los pasos

**Tanda A — Promociones (backend + motor).**

| # | Paso |
|---|---|
| 0 | Reconciliar la numeración de la hoja de ruta y los `.module.md` (§0) |
| 1 | Esquema: `promotions`, `promotion_targets`, `promotion_branches`. CRUD de definiciones con `version` |
| 2 | El contrato del kernel `PromotionResolver` + DTOs de primitivos, y el null-object de degradación |
| 3 | El motor en `Promotions`: vigencia, ventana horaria en zona de sucursal, «mejor gana» y la excepción por config (D20) |
| 4 | El POS consume el resolver al recalcular la cuenta; escribe el efecto en `pos_discounts` (`source=promotion`) |
| 5 | `promotion_applications` (inmutable) + evento del kernel + el oyente que la registra; `FinancialMovementType::Promotion` y su asiento |

**Tanda B — Clientes/CFDI-ready.**

| # | Paso |
|---|---|
| 6 | Catálogos SAT (`sat_tax_regimes`, `sat_cfdi_uses`) sembrados y versionados |
| 7 | `customer_fiscal_profiles` (0..N, RFC por forma, predeterminado con centinela) + rutas del permiso `customers.fiscal_profiles.manage` |
| 8 | `customer_addresses` (0..N, columnas mexicanas) + rutas del permiso `customers.addresses.manage` |
| 9 | `birthday` en `customers`; el historial agregado `GET /customers/{customer}/history` |
| 10 | (Si se aprueba §4.4) snapshot fiscal congelado en el ticket facturable al cobrar |

**Tanda C — Frontend y verificación.**

| # | Paso |
|---|---|
| 11 | Pantalla de promociones (definiciones) y el precio promocional previsualizado en la cuenta |
| 12 | Expediente del cliente: perfiles fiscales, direcciones, cumpleaños, historial |
| 13 | Verificación en navegador de las tres superficies, **con dos sucursales y ventana horaria activa** |

Es una iteración grande —comparable a la 3—, y las tandas son los cortes naturales si prefieres cerrar por partes.

---

## 7. Candados que se activan

Todos los estructurales existentes (quince), y en particular: `ImmutableTablesTest` (obliga a declarar
`promotion_applications` inmutable en §7 + el test + el trait), `CrossModuleEventsTest` (el evento nuevo con primitivos),
`ModuleBoundariesTest` (que `Promotions` no gane un `depends_on` y el POS tampoco), `BranchScopeIsAssertedTest` (las
promociones y perfiles se acotan por sucursal donde aplique), `TenantScopeTest` (toda tabla nueva con global scope),
`DemoPurgeCoversTenantTablesTest` (las tablas nuevas se purgan con `--fresh`), `FrontendFiltersExistTest` y
`RoutePermissionTest`. Y el **test de aislamiento de tenant por módulo** para `Promotions` y para las tablas nuevas de
`Customers` (Definition of Done).

---

## 8. Lo que necesito de ti

**Aprobación explícita** antes de la primera migración, y en particular de estas cuatro decisiones (mi recomendación es
la primera opción de cada una):

1. **El motor vive en `Promotions` y el POS lo consume por un probe del kernel** (`PromotionResolver`), con el efecto
   escrito en `pos_discounts` y el registro por venta en `promotion_applications` alimentado por evento (§2). Es la
   frontera correcta, pero es la decisión de más peso.
2. **Los cupones se difieren a la Iteración 8** (e-commerce); esta iteración hace los tres tipos POS y el motor completo
   (§3.1).
3. **La promoción tiene su propio `FinancialMovementType::Promotion`**, separada del descuento manual para el reporte
   antifraude (§3.5).
4. **El snapshot fiscal se congela en el ticket facturable al cobrar** (§4.4) — o se difiere al timbrado, dejando la
   iteración enteramente en `Customers`/`Promotions`.

**Y estas decisiones de producto**, que conviene cerrar aquí:

| Pregunta | Contexto |
|---|---|
| ¿El **Cajero** debería poder registrar un abono de crédito? Hoy no puede (`finance.customer_credit.manage` es sólo de Propietario/Gerente), aunque el abono entra a **su** caja | Lo destapó la lectura del módulo existente; puede ser fricción real de mostrador |
| ¿La validación CFDI de v1 llega hasta **régimen↔persona** y deja la matriz régimen↔uso para el timbrado? | §4.2 |
| ¿La «excepción configurable» de no acumulables es toggle por **tenant/sucursal** (D20)? | §3.4 |

**Y las decisiones arrastradas** que siguen abiertas sin bloquear (D219, D221, D230 desde la Iteración 3; D292 listado
fuera de alcance 403-vs-vacío; D305 mesero-con-cobro y fiar; D308 comandas «preparado»). No las toca esta iteración,
pero conviene ir cerrándolas.
