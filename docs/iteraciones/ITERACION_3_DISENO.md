# Iteración 3 — Inventarios + Compras · DISEÑO

> **Estado: APROBADO. Implementación en curso — pasos 1 a 9 cerrados.**
> Las decisiones P1, P2 y P7 están resueltas (D154, D152, D153). El resto de la sección 11 se aprobó con la
> recomendación que lleva escrita.
>
> **Pasos 1 a 3 entregados:** `stock_movements`, `article_stocks`, `article_lots`, el servicio de registro con
> lock pesimista, la API de existencias y kardex, los tres endpoints de movimiento manual y la matriz de
> autorización de los dieciocho permisos, `articles.tracks_lots`, la API de lotes y **FEFO** en las salidas.
>
> **Paso 4 entregado:** mermas con catálogo de motivos por tenant, umbral de monto por sucursal y autorización
> por PIN de un superior — el primer uso de ADR-008 fuera de su propio endpoint (D168–D174).
>
> **Paso 5 entregado:** conteos físicos con congelamiento de lo esperado, conteo CIEGO para quien captura, cierre
> con ajuste masivo idempotente y umbral de autorización firmado por el propietario (D175–D183).
>
> **Paso 6 entregado:** transferencias con máquina de estados de cinco pasos, dos de ellos omitibles por
> configuración, y **almacén de tránsito** para que la mercancía en viaje no desaparezca del sistema (D184–D191).
>
> **Paso 7 entregado:** producción con snapshot real de la receta usada, escalado por unidad, rendimiento aplicado a la
> cantidad física y permiso propio (D192–D198).
>
> **Paso 8 entregado:** el módulo `Purchasing` con `suppliers` y el historial inmutable `supplier_prices`, normalizado a
> unidad base, con la comparación entre proveedores y la detección de subidas (D199–D205).
>
> **Paso 9 entregado:** recepciones de compra con los tres efectos por evento —kardex, costo con `origin = purchase`, y
> observación de precio—, reversa con tipo de movimiento propio, y el IVA de compras configurable con su criterio
> congelado (D206–D214). Faltan los pasos 10 (aislamiento y candados) y 11 (UI de inventarios y compras).
>
> **Decisión de diseño que el documento no anticipaba:** el faltante de una salida FEFO va a la fila «sin lote»
> y no al último lote usado (D163) — un lote en negativo ordenaría primero y absorbería todas las salidas
> siguientes.
>
> **Corrección al §7 de este documento:** el endpoint único `POST /stock-movements` que proponía resultó
> inviable — `can:` recibe un permiso y un `kind` libre en el cuerpo sería un agujero de dominio. Son tres
> endpoints, uno por permiso (D158).
>
> **Segunda decisión que el documento no anticipaba:** la falta de autorización de una merma responde **409** con
> `authorization_required`, no 422 (D170). No hay nada en el cuerpo que corregir: falta la firma de otra persona.
> Establece el patrón que usarán descuentos y cancelaciones en la Iteración 5.
>
> **Correcciones al §2.6 de este documento:** el conteo **no tiene estado borrador** —congelar es lo primero que
> pasa, no un paso posterior (D175)— y hay **dos columnas de diferencia valuada** en lugar de una, porque el neto y
> el bruto contestan preguntas distintas y el umbral se mide sobre el bruto (D180). Se añadieron dos rutas que el
> §7 no listaba: `POST /stock-counts/{ulid}/cancel`, obligatoria porque sólo cabe un conteo abierto por almacén
> (D176), y el permiso `inventory.counts.authorize_above_threshold`, que es del **propietario** (D179).
>
> **Y una decisión de negocio que el documento no planteaba:** el conteo es **ciego** para quien captura (D178). No
> es una regla nueva: es el mismo control que §6.3 ya aplica al efectivo con `pos.blind_precount`.
>
> **Corrección al §2.7 de este documento:** la merma por diferencia en tránsito **NO va en el almacén de origen**
> como decía el diseño (D185). Sería un doble cargo: el origen ya bajó las 100 que subieron al camión, y restarle
> otras 5 dejaría el inventario 105 abajo cuando sólo se perdieron 5. Va en el almacén de **tránsito**, que es una
> pieza nueva que el documento no contemplaba (D184) y sin la cual la pérdida no aparecería en el reporte de mermas.
>
> Y dos restricciones que el §2.7 no anticipaba: una transferencia **entre dos almacenes centrales se rechaza**,
> porque el folio va por sucursal y ninguno tiene (D189); y una transferencia **enviada no se puede cancelar** — la
> mercancía está en un camión y el único cierre es recibirla.
>
> **Corrección al §2.8 de este documento:** `recipe_snapshot_id → recipes` **no congela nada** (D192). El razonamiento
> del documento es correcto —una orden de marzo no debe explicarse con la receta de agosto— y la solución no funciona,
> porque `recipes` es una fila por artículo, mutable y sin versiones. El snapshot real son las líneas de la orden, y se
> escriben al **completar**, no al planear (D193).
>
> **Corrección al §7:** producción tiene **permiso propio** `inventory.production.create`, no `inventory.entries.create`
> (D197): producir consume inventario además de generarlo. Y **deuda declarada:** un componente producible que no se
> inventaría se rechaza en lugar de explotar su sub-receta (D194).
>
> **Precisión al §3.3:** `unit_price` es **siempre por unidad base** (D203). El documento no lo decía y sin ello la
> comparación entre proveedores es imposible: el que vende en cajas de 12 kg saldría once mil veces más caro que el que
> cotiza por gramo. Y la comparación **agrupa por moneda** (D204), porque no hay tipo de cambio en el sistema.
>
> `depends_on` de `Purchasing` estaba en `[]` y era falso: depende de `Catalog` (D199).
>
> **Decisión del dueño en el §3.2:** el IVA de compras es **acreditable por configuración**, con el acreditable por
> omisión (D206). El documento guarda siempre la verdad de la factura y el ajuste sólo decide qué costo va a `Costing`;
> el criterio se congela en cada recepción. **Riesgo registrado, en la línea de D150:** cambiar el ajuste no recalcula los
> costos ya capturados.
>
> **Correcciones al §3.2:** hizo falta un tipo de movimiento nuevo, `purchase_return`, porque `purchase_receipt` tiene
> dirección fija de entrada y la reversa tiene que salir (D210). Y el folio de una recepción en almacén central sale de
> la **sucursal activa de quien recibe** — la primera versión rechazaba recibir en central, que es el caso normal de una
> cadena (D214).
>
> **Defecto de la Iteración 2 que este paso destapó:** `article_costs.idempotency_key` existía con su índice único y
> **no hacía la operación idempotente** — el reintento reventaba con un 500 (D212).

**Alcance (hoja de ruta §14, iteración 3):** kardex, existencias, lotes/FEFO, transferencias, mermas,
conteos físicos, proveedores y recepciones de compra.

**Módulos:** `Inventory` y `Purchasing`, los dos ya declarados en `config/comandia.php` con iteración 3.

---

## 1. Las tres preguntas que definen la iteración

### 1.1 ¿Qué es la existencia de un artículo?

**Un acumulado, no un dato.** §6.2 es explícito: *«kardex como fuente de verdad; existencia como
acumulado»*. Es la misma forma que ya usa el costeo —historial inmutable + proyección— y por la misma
razón: el saldo se puede reconstruir, el movimiento no.

De ahí sale la estructura entera de esta iteración:

```
stock_movements   (INMUTABLE, append-only)   ← la verdad
      ↓ proyección
article_stocks    (saldo por artículo/almacén) ← lo que se lee mil veces al día
```

Nunca se corrige un movimiento: se registra el contrario. Es lo que hace que un inventario mal capturado
tenga historia en lugar de tener un número distinto.

### 1.2 ¿Qué pasa cuando el inventario y la realidad no coinciden?

**Gana la realidad, y la diferencia queda registrada.** §6.2: el inventario del sistema es **teórico** y se
reconcilia con **conteos físicos formales** (conteo → variance → ajuste masivo auditado).

Esto no es una concesión: es la única postura honesta en un negocio de alimentos, donde el jitomate se cae,
se comparte con la casa de al lado y se lo lleva alguien. Un sistema que pretenda que su número es la
verdad obliga a mentirle para que cuadre.

### 1.3 ¿Puede el inventario detener una venta?

**No, nunca.** §6.2 y §6 lo fijan: la venta siempre procede, el descuento de insumos es **asíncrono** y las
existencias negativas **están permitidas**.

Es la regla que más forma le da al diseño: significa que `stock_movements` acepta llevar el saldo a
negativo, que el descuento por venta llega por evento y por cola, y que **la única defensa contra el
descuadre es el conteo**, no un bloqueo.

La tienda en línea es la excepción declarada (§6, D48): **sí** respeta stock, configurable por artículo. Eso
llega en la Iteración 9 y el modelo lo soporta sin cambios — es una lectura de `article_stocks` antes de
aceptar el pedido.

---

## 2. Módulo `Inventory`

### 2.1 `stock_movements` — el kardex

La tabla más importante de la iteración, y **inmutable** (§7).

```
id                      BIGINT UNSIGNED PK
ulid                    CHAR(26) ascii_bin UNIQUE
tenant_id               BIGINT UNSIGNED NOT NULL   → tenants
warehouse_id            BIGINT UNSIGNED NOT NULL   → warehouses
article_id              BIGINT UNSIGNED NOT NULL   → articles
lot_id                  BIGINT UNSIGNED NULL       → article_lots   (NULL = artículo sin lotes)

kind                    ENUM(...)                  -- ver 2.2
direction               ENUM('in','out')           -- redundante con kind, y a propósito: ver abajo
quantity                DECIMAL(12,4) UNSIGNED NOT NULL  -- SIEMPRE positiva
unit_cost               DECIMAL(12,4) NULL         -- costo unitario del movimiento
total_cost              DECIMAL(12,2) NULL         -- cantidad × costo, congelado

balance_after           DECIMAL(12,4) NOT NULL     -- saldo del (almacén, artículo, lote) tras el movimiento

source_type             VARCHAR(120) ascii_bin NULL  -- documento origen
source_id               BIGINT UNSIGNED NULL
source_ulid             CHAR(26) ascii_bin NULL      -- identificador público, congelado (como D151)

idempotency_key         VARCHAR(120) ascii_bin NULL UNIQUE por tenant

actor_membership_id     BIGINT UNSIGNED NULL RESTRICT
notes                   VARCHAR(200) NULL
occurred_at             DATETIME(3) NOT NULL
created_at              DATETIME(3) NOT NULL
```

**`quantity` siempre positiva y `direction` aparte.** La alternativa —cantidad con signo— parece más
compacta y es una trampa: un `SUM(quantity)` sin mirar el signo da un número plausible y equivocado, y ese
error no revienta, se acumula. Con dirección explícita, cualquier suma tiene que decidir qué hace con las
entradas y las salidas.

**`balance_after` congelado en el movimiento.** Es denormalización deliberada y la justificación es concreta:
es lo que permite leer el kardex de un artículo como un estado de cuenta —fila por fila, con el saldo a la
derecha— sin recalcular acumulados en el cliente ni en la consulta. Y es lo que hace **auditable** la
proyección: si `article_stocks` se desvía del último `balance_after`, hay un problema y se puede ver.

Su precio: exige que los movimientos de un mismo `(almacén, artículo, lote)` se inserten **serializados**.
Se resuelve con un lock pesimista sobre la fila de `article_stocks` dentro de la transacción, que es el mismo
patrón que la foliación por sucursal (§7).

**`source_ulid` junto a `source_id`.** Exactamente la lección de D151: la llave interna deja de significar
algo si el documento desaparece, y no se puede exponer por la API. Se congela el público desde el día uno en
lugar de agregarlo después.

**Índices:**

| Índice | Justificación |
|---|---|
| `(tenant_id, warehouse_id, article_id, occurred_at)` | El kardex de un artículo en un almacén, en orden. Es **la** consulta de la pantalla de kardex y del cálculo de saldo |
| `(tenant_id, article_id, occurred_at)` | El kardex de un artículo en **todos** los almacenes: la vista del dueño que quiere saber dónde está su queso |
| `(tenant_id, source_type, source_id)` | «¿Qué movimientos generó esta recepción / esta transferencia / esta venta?» — es la trazabilidad inversa, y sin índice sería un recorrido completo de la tabla más grande |
| `(tenant_id, kind, occurred_at)` | Reportes por tipo de movimiento: mermas del mes, ajustes del periodo |
| `(tenant_id, idempotency_key)` UNIQUE | La garantía de que re-despachar un job no duplica un movimiento |

Cuatro índices sobre la tabla de mayor volumen es mucho, y es la razón por la que cada uno está
justificado por una consulta concreta. Si al implementar alguno no tiene consulta, no se crea.

### 2.2 Tipos de movimiento

```
purchase_receipt     in    Recepción de compra
transfer_out         out   Salida por transferencia
transfer_in          in    Entrada por transferencia
production_in        in    Producción de un artículo producible
production_out       out   Consumo de insumos por producción
sale_consumption     out   Descuento por venta (asíncrono, POS)
sale_return          in    Reverso de un descuento por venta
waste                out   Merma tipificada
count_adjustment     in/out Ajuste por conteo físico
manual_adjustment    in/out Ajuste manual con motivo
initial_load         in    Carga inicial de existencias
```

`count_adjustment` y `manual_adjustment` admiten las dos direcciones porque un ajuste puede sumar o restar;
los demás tienen dirección fija y el modelo la impone.

### 2.3 `article_stocks` — la proyección

```
id, tenant_id, warehouse_id, article_id, lot_id (NULL)
quantity                DECIMAL(12,4) NOT NULL DEFAULT 0   -- puede ser NEGATIVA
last_movement_id        BIGINT UNSIGNED NULL → stock_movements
updated_at              DATETIME(3)

UNIQUE (tenant_id, warehouse_id, article_id, lot_key)
```

`lot_key` es una columna **generada STORED** que resuelve el `NULL` del lote, el mismo patrón de D93: sin
ella, dos filas con `lot_id NULL` no colisionarían y un artículo sin lotes acabaría con dos saldos.

**Sin `total_value`.** La tentación es guardar el valor del inventario aquí; no se hace, porque el valor
depende del método de valuación (P2) y guardarlo crearía una segunda verdad que se desviaría del kardex. Se
calcula al leer.

Índices: el UNIQUE de arriba, más `(tenant_id, article_id)` para «¿dónde tengo este artículo?» y
`(tenant_id, warehouse_id, quantity)` para «¿qué está por debajo de mínimo en este almacén?» — este último
sólo si se implementa el mínimo (P6), y si no, no se crea.

### 2.4 `article_lots` — lotes y caducidades (D23)

Opcionales **por artículo**: `articles.tracks_lots BOOLEAN` (columna nueva en `Catalog`).

```
id, ulid, tenant_id
article_id       → articles
code             VARCHAR(40) ascii_bin NOT NULL   -- el que trae el proveedor
expires_at       DATE NULL
received_at      DATE NOT NULL
status           ENUM('active','depleted','expired')

UNIQUE (tenant_id, article_id, code)
INDEX (tenant_id, article_id, expires_at)   -- FEFO: primero lo que caduca
```

**FEFO automático, sin selección manual obligatoria** (§6.2). La salida elige lotes por `expires_at`
ascendente, con `NULL` al final —lo que no caduca sale después de lo que sí— y puede **partirse en varios
movimientos** si un lote no alcanza. Esa es la razón de que una salida sea potencialmente N movimientos: se
diseña así desde el principio en lugar de asumir uno.

Un lote **no se puede reasignar de artículo** ni cambiar su `code`: es la misma regla que la unidad base
(D96) y la cantidad de una presentación (D147) — reinterpretaría los movimientos que ya lo citan.

### 2.5 Mermas — `waste_reasons` y el movimiento

Catálogo de motivos **por tenant** (§6.2, D27):

```
waste_reasons: id, ulid, tenant_id, name, requires_evidence BOOLEAN, status
UNIQUE (tenant_id, name)
```

La merma **no es una tabla propia**: es un `stock_movement` de tipo `waste` con `waste_reason_id`. Un
documento aparte duplicaría la cantidad y el costo, y esa duplicación es exactamente de donde salen los
descuadres entre «reporte de mermas» y «kardex».

Tres reglas de §6.2 que el diseño tiene que soportar:

1. **Permiso específico** — `inventory.waste.create`, ya en el catálogo.
2. **Umbral de monto con autorización superior** — `inventory.waste.authorize_above_threshold` + PIN
   (ADR-008). El umbral es configuración (`inventory.waste_authorization_threshold`), y el actor real queda
   en `authorized_by_membership_id` de la bitácora, que es la columna que existe justo para esto.
3. **Evidencia fotográfica opcional** — ver P5, porque el almacenamiento de archivos no existe todavía.

### 2.6 Conteos físicos (D24) — `stock_counts` y `stock_count_lines`

El flujo de §6.2: **conteo → variance → ajuste masivo auditado**.

```
stock_counts:  id, ulid, tenant_id, warehouse_id, status, started_by, closed_by,
               started_at, closed_at, notes
               ENUM status: draft → counting → closed | cancelled

stock_count_lines: id, tenant_id, stock_count_id, article_id, lot_id NULL,
                   expected_quantity  DECIMAL(12,4)  -- congelado al abrir la línea
                   counted_quantity   DECIMAL(12,4) NULL
                   variance           DECIMAL(12,4) generada STORED
                   unit_cost_at_count DECIMAL(12,4) NULL
```

**`expected_quantity` se congela**, no se lee al cerrar. Es la diferencia entre un conteo y una foto
borrosa: si se leyera al cerrar, cualquier movimiento ocurrido durante el conteo cambiaría la diferencia y
el resultado dependería de cuánto tardó quien contaba.

Al **cerrar**, cada línea con `variance != 0` genera un `count_adjustment` con el conteo como `source`. Un
conteo cerrado es inmutable; corregir un conteo mal hecho es hacer otro.

Un conteo **no bloquea el almacén** — coherente con «el POS nunca se bloquea».

### 2.7 Transferencias (D25) — `transfers` y `transfer_lines`

Máquina de estados completa de §6.2, con **pasos omitibles por configuración**:

```
requested → authorized → preparing → shipped → received
                                            ↘ received_with_differences
      ↘ cancelled (desde requested o authorized)
```

```
transfers: id, ulid, tenant_id,
           origin_warehouse_id, destination_warehouse_id,
           status, folio (por tenant+sucursal+tipo+serie, §7),
           requested_by, authorized_by, prepared_by, shipped_by, received_by  (membresías)
           requested_at, authorized_at, prepared_at, shipped_at, received_at
           notes

transfer_lines: id, tenant_id, transfer_id, article_id, lot_id NULL,
                requested_quantity, shipped_quantity NULL, received_quantity NULL
```

**Tres cantidades por línea y no una.** Es lo que permite contestar «¿se pidió poco, se mandó poco o se
perdió en el camino?», y sin las tres esa pregunta no tiene respuesta. La **recepción con diferencias genera
merma en tránsito automática** (§6.2): un movimiento `waste` con motivo del sistema «Diferencia en
tránsito», atribuido al almacén de **origen**, porque es de donde salió.

Los pasos omitibles son configuración por tenant, y la máquina de estados vive en el dominio con las
transiciones válidas declaradas — el mismo patrón que las transiciones de estado del tenant.

### 2.8 Producción

Un artículo **producible** (D17) con receta (D16) se puede *producir*: consume sus ingredientes y genera
existencia de sí mismo. Es lo que conecta esta iteración con el costeo de la anterior.

```
production_orders: id, ulid, tenant_id, warehouse_id, article_id,
                   quantity_produced DECIMAL(12,4),
                   status ENUM('draft','completed','cancelled'),
                   recipe_snapshot_id → recipes,   -- QUÉ receta se usó
                   produced_by, produced_at
```

**`recipe_snapshot_id`** porque las recetas cambian: sin él, un lote producido en marzo se explicaría con la
receta de agosto. Es la misma razón por la que el historial de precios congela el costo del momento (D15).

Al completarse: N movimientos `production_out` (los insumos, con FEFO si llevan lotes) y uno
`production_in` (el producible). Todos con la orden como `source`, así que la trazabilidad es reversible.

---

## 3. Módulo `Purchasing`

### 3.1 `suppliers` (D26)

```
id, ulid, tenant_id, code, legal_name, trade_name NULL,
rfc NULL, contact_name NULL, phone NULL, email NULL,
payment_terms_days SMALLINT NULL, status
UNIQUE (tenant_id, code)
```

Sin cuentas por pagar en v1: §6.2 dice «compras v1 mínimas». `payment_terms_days` se guarda como dato del
proveedor porque es información que se pierde si no se captura al darlo de alta, y no cuesta nada.

### 3.2 `purchase_receipts` — recepción directa

§6.2: *«proveedor + recepción directa con costos → alimenta inventario e historial de costos»*. **No hay
orden de compra en v1** — es la simplificación declarada de D26, y la deuda es que no se puede comparar «lo
pedido» con «lo recibido» hasta que exista.

```
purchase_receipts: id, ulid, tenant_id, supplier_id, warehouse_id,
                   folio, supplier_document_number NULL,   -- el folio de SU factura
                   received_at, subtotal, tax_total, total,
                   status ENUM('draft','confirmed','cancelled'),
                   received_by, confirmed_at, notes

purchase_receipt_lines: id, tenant_id, purchase_receipt_id, article_id,
                        presentation_id NULL → article_purchase_presentations,
                        quantity DECIMAL(12,4),      -- en la unidad de captura
                        quantity_in_base_unit DECIMAL(12,4),  -- convertida, congelada
                        unit_price DECIMAL(12,4), line_total DECIMAL(12,2),
                        lot_code NULL, expires_at NULL
```

**`presentation_id` + cantidad convertida y congelada.** Aquí se cobra lo construido en la Iteración 2:
se recibe «3 cajas de 12 kg» y el sistema guarda las 36 000 g. La conversión se congela porque la
presentación se puede dar de baja y su cantidad es inmutable (D147) — pero el movimiento tiene que seguir
siendo legible dentro de tres años.

**Confirmar la recepción** dispara dos cosas, las dos por evento:

1. `Inventory` registra un `purchase_receipt` por línea (creando lotes si el artículo los lleva).
2. `Costing` captura el costo con `origin = purchase` — el valor del enum que **existe desde la Iteración 2
   y todavía no se usa**. Es el punto de conexión que estaba esperando.

Una recepción confirmada **no se edita**: se cancela con una recepción de reverso. Los movimientos de
inventario ya existen y el costo ya está en el historial.

### 3.3 `supplier_prices` — catálogo de precios por proveedor (D26)

*«Para comparación y detección de subidas»* (§6.2). Es un **historial**, no un precio vigente:

```
supplier_prices: id, ulid, tenant_id, supplier_id, article_id,
                 presentation_id NULL,
                 unit_price DECIMAL(12,4), currency CHAR(3) DEFAULT 'MXN',
                 observed_at DATE, source ENUM('receipt','quote','manual'),
                 purchase_receipt_id NULL
INDEX (tenant_id, article_id, observed_at)
```

Append-only por la misma razón que el historial de costos: la pregunta que resuelve es «¿me subió el precio
este proveedor?», y con un solo precio vigente por proveedor esa pregunta no tiene respuesta.

Se alimenta **automáticamente** de cada recepción confirmada. Capturarlo a mano sería un catálogo que nadie
mantiene.

---

## 4. Eventos y fronteras

`Inventory` y `Purchasing` no se llaman entre sí. Todo cruza por eventos (§2):

| Evento | Emisor | Escucha | Efecto |
|---|---|---|---|
| `PurchaseReceiptConfirmed` | `Purchasing` | `Inventory` | Movimientos de entrada + lotes |
| `PurchaseReceiptConfirmed` | `Purchasing` | `Costing` | `CaptureArticleCost` con `origin = purchase` |
| `PurchaseReceiptConfirmed` | `Purchasing` | `Purchasing` | Registra `supplier_prices` |
| `StockMovementRecorded` | `Inventory` | — | Disponible para reportes y para el mínimo (P6) |
| `TransferReceived` | `Inventory` | `Inventory` | Merma en tránsito si hay diferencias |
| `ItemsCommanded` (Iteración 4) | `Pos` | `Inventory` | Descuento asíncrono por venta |

**Dependencias de módulo declaradas:** `Inventory → [Catalog]`, `Purchasing → [Catalog, Inventory]`.
`Purchasing` depende de `Inventory` para conocer el almacén de recepción; el revés no existe. El candado de
fronteras (D92) lo impone.

**Jobs idempotentes** (§7, obligatorio): el descuento por venta y el recálculo de costo llevan llave de
idempotencia por documento origen + tipo. Re-despachar nunca duplica un movimiento — es el patrón de D109
a D112, ya construido y probado.

---

## 5. Configuración nueva

| Clave | Tipo | Alcance máx. | Por qué |
|---|---|---|---|
| `inventory.waste_authorization_threshold` | Decimal | Sucursal | §6.2 exige umbral con autorización superior. Por sucursal porque el volumen de un bar y de una fonda no se parecen |
| `inventory.transfer_skip_authorization` | Bool | Tenant | «Pasos omitibles por configuración» (§6.2) |
| `inventory.transfer_skip_preparation` | Bool | Tenant | Idem |
| `inventory.allow_negative_stock` | Bool | Tenant | **Ver P4.** El POS nunca se bloquea, pero una entrada manual sí podría avisar |
| `inventory.valuation_method` | Enum | Tenant | **Ver P2** |
| `purchasing.require_supplier_document` | Bool | Sucursal | Si el folio de la factura del proveedor es obligatorio |

`inventory.warehouse_mode` ya existe desde la Iteración 1 y decide de qué almacén descuenta el consumo.

---

## 6. Permisos

**Todos existen ya** en el catálogo cerrado (D10): catorce de `inventory.*` y cuatro de `purchasing.*`. No
se agrega ninguno, lo que es buena señal — significa que el catálogo se diseñó pensando en esta iteración.

Dos huecos que la revisión de la Iteración 2 obliga a mirar (§14 de `ARQUITECTURA_MAESTRA`):

- `purchasing.receipts.create` existe; **no** hay permiso para *confirmar* una recepción. Si confirmar es la
  acción que mueve inventario y dinero, quizá deba ser distinta de capturarla. **Ver P7.**
- `inventory.lots.manage` existe y con FEFO automático puede que no haga falta administrar lotes a mano.
  Queda para la revisión de cierre: un permiso sin ruta es un permiso que engaña.

---

## 7. API — endpoints propuestos

```
GET    /stocks                              inventory.stock.view
GET    /articles/{ulid}/stock               inventory.stock.view
GET    /articles/{ulid}/kardex              inventory.kardex.view      (cursor)
POST   /stock-movements                     inventory.entries|exits|adjustments.create
GET    /warehouses/{ulid}/stocks            inventory.stock.view

GET    /waste-reasons                       inventory.waste.create
POST   /waste-reasons                       inventory.waste.create
POST   /waste                               inventory.waste.create

GET    /stock-counts                        inventory.counts.create
POST   /stock-counts                        inventory.counts.create
GET    /stock-counts/{ulid}                 inventory.counts.create
PUT    /stock-counts/{ulid}/lines           inventory.counts.create
POST   /stock-counts/{ulid}/close           inventory.counts.close

GET    /transfers                           inventory.transfers.request
POST   /transfers                           inventory.transfers.request
POST   /transfers/{ulid}/authorize           inventory.transfers.authorize
POST   /transfers/{ulid}/prepare              inventory.transfers.prepare
POST   /transfers/{ulid}/ship                 inventory.transfers.ship
POST   /transfers/{ulid}/receive              inventory.transfers.receive

GET    /production-orders                    inventory.entries.create
POST   /production-orders                    inventory.entries.create
POST   /production-orders/{ulid}/complete     inventory.entries.create

GET    /suppliers                            purchasing.suppliers.view
POST   /suppliers                            purchasing.suppliers.manage
PATCH  /suppliers/{ulid}                     purchasing.suppliers.manage
GET    /purchase-receipts                    purchasing.receipts.create
POST   /purchase-receipts                    purchasing.receipts.create
POST   /purchase-receipts/{ulid}/confirm      purchasing.receipts.create   ← ver P7
GET    /articles/{ulid}/supplier-prices       purchasing.supplier_prices.view
```

El kardex se pagina **por cursor** y no por número de página: es la tabla que más crece del sistema y no
existe una «página 400» a la que alguien quiera saltar. Es el patrón que ya usa la bitácora.

---

## 8. Qué NO entra

| Fuera | Por qué |
|---|---|
| Órdenes de compra | D26: compras v1 mínimas. La deuda: no se puede comparar lo pedido con lo recibido |
| Cuentas por pagar | Es finanzas (Iteración 5) |
| Descuento por venta | Necesita el POS (Iteración 4). El evento y el job se diseñan aquí y se conectan allá |
| Mínimos y sugerencia de reorden | **Ver P6** |
| Inventario en consignación, series | Fuera de v1 |
| Costeo por promedio ponderado como método único | **Ver P2** |

---

## 9. Pruebas (Definition of Done)

Además de lo que CLAUDE.md exige siempre:

1. **Inmutabilidad del kardex** por las tres vías, y en la lista de §7 con su candado (D130).
2. **Aislamiento de tenant** de las nueve tablas nuevas, con barrido sistemático y candado de completitud.
3. **Matriz de autorización** de los dieciocho permisos, exhaustiva y con candado de cobertura (D128).
4. **Idempotencia**: re-despachar el descuento y la captura de costo no duplica movimientos.
5. **FEFO**: una salida mayor que el lote más próximo a caducar se parte en varios movimientos, en orden.
6. **Concurrencia de `balance_after`**: dos movimientos simultáneos sobre el mismo `(almacén, artículo,
   lote)` no producen dos veces el mismo saldo. Es la prueba que más importa y la más fácil de no escribir.
7. **Conteo**: `expected_quantity` congelada; un movimiento durante el conteo **no** cambia la diferencia.
8. **Transferencia**: la máquina de estados rechaza los saltos, y la recepción con diferencias genera la
   merma en tránsito en el almacén de origen.
9. **Existencias negativas permitidas**, y el candado de que ninguna ruta del POS las bloquea.
10. **Todo endpoint llamado** — ya es candado (D146).

---

## 10. Orden de implementación propuesto

1. `stock_movements` + `article_stocks` + el servicio de registro con lock. **Todo lo demás depende de
   esto**, y un error en el saldo contamina cada número del módulo.
2. Carga inicial y ajustes manuales: la vía mínima para tener existencias con que probar.
3. `article_lots` + FEFO en las salidas.
4. Mermas: motivos, umbral, autorización por PIN.
5. Conteos: apertura, captura, cierre con ajuste masivo.
6. Transferencias: máquina de estados y merma en tránsito.
7. Producción: consumo de receta y entrada del producible.
8. `Purchasing`: proveedores.
9. Recepciones + eventos hacia `Inventory` y `Costing` + `supplier_prices`.
10. Aislamiento, matriz de autorización y candados.
11. UI de inventarios.

Los pasos 1 a 3 son el núcleo: si algo se sale de tiempo, lo que se recorta es de 6 en adelante, nunca de
1 a 5.

---

## 11. Decisiones que los documentos maestros NO cubren — **ABIERTAS**

| # | Pregunta | Mi recomendación |
|---|---|---|
| **P1** | ¿`balance_after` congelado en el movimiento, o saldo sólo en la proyección? | **RESUELTA** (D154): congelado, con lock pesimista sobre la fila del saldo |
| **P2** | Método de valuación: ¿último costo, o promedio ponderado? | **RESUELTA** (D152): último costo; el promedio se calcula del kardex como reporte |
| **P3** | ¿El lote es del artículo, o del artículo **en un almacén**? | Del artículo; el saldo por almacén lo lleva `article_stocks` |
| **P4** | Existencias negativas: prohibidas siempre no; ¿y en entradas/salidas manuales? | Advertir, no bloquear |
| **P5** | Evidencia fotográfica de mermas | **Diferir**: no hay almacenamiento de archivos todavía |
| **P6** | ¿Mínimos por artículo/almacén y sugerencia de reorden en esta iteración? | **No**, diferir a Reportes |
| **P7** | ¿Confirmar una recepción necesita permiso propio? | **RESUELTA** (D153): sí, `purchasing.receipts.confirm`, que se agrega en el paso 9 junto con su ruta |
| **P8** | ¿La producción es un documento, o un movimiento con receta? | Documento (`production_orders`) |

### P1 — `balance_after` en el movimiento

**Recomiendo congelarlo.** Da tres cosas concretas: el kardex se lee como un estado de cuenta sin acumular
en el cliente, la proyección se vuelve **auditable** —si `article_stocks` no coincide con el último
`balance_after`, hay un problema visible— y el saldo histórico de cualquier fecha se lee en una fila en
lugar de sumar toda la tabla.

Su precio hay que decirlo: obliga a **serializar** las inserciones del mismo `(almacén, artículo, lote)` con
un lock pesimista. Eso significa que dos recepciones simultáneas del mismo artículo en el mismo almacén se
esperan una a la otra, unos milisegundos. En un negocio de alimentos eso no es contención real; en un
almacén con carga masiva sí lo sería.

La alternativa —saldo sólo en la proyección— evita el lock y a cambio hace que «¿cuánto tenía el 3 de
marzo?» sea una suma de toda la historia del artículo.

### P2 — Valuación: último costo o promedio ponderado

D14 fija «último costo + historial de variaciones» **para el costeo de recetas**. Valuar el inventario es
otra pregunta y no está decidida.

**Recomiendo último costo en v1**, con el promedio ponderado como **reporte** calculado del kardex:

- Es coherente con lo ya construido: el costeo en cascada usa el costo vigente, así que el valor del
  inventario y el costo de los platillos hablarían del mismo número. Con promedio ponderado habría **dos**
  costos del mismo artículo y la primera pregunta de cualquier dueño sería cuál es el bueno.
- El promedio ponderado exige recalcular el costo en cada entrada y guardarlo por capa; es correcto y es
  bastante más máquina.
- Como reporte se puede calcular cuando se quiera, porque el kardex guarda `unit_cost` de cada entrada.

**Si me dices que la contabilidad del negocio exige promedio ponderado**, cambia el diseño: `article_stocks`
llevaría `average_cost` y cada entrada lo recalcularía dentro del mismo lock. Es mejor decidirlo ahora que
después de tener movimientos.

### P3 — ¿El lote pertenece al artículo o al artículo en un almacén?

**Recomiendo del artículo.** El mismo lote de leche puede estar repartido entre la matriz y Polanco, y su
caducidad es la misma en los dos sitios: es una propiedad del lote, no del almacén. El saldo por almacén lo
lleva `article_stocks`, que ya tiene las tres columnas.

La alternativa —lote por almacén— duplicaría la caducidad en cada almacén, con el riesgo evidente de que se
capturen distintas.

### P4 — Existencias negativas

§6.2 las permite y no dice nada de las entradas manuales. **Recomiendo advertir sin bloquear**: la salida
manual que deja negativo se registra y la respuesta lo dice, con `inventory.allow_negative_stock` para el
tenant que quiera rechazarlas.

Bloquear por omisión contradiría el espíritu de la regla —el sistema no impide operar— y además la causa
más común de un negativo no es un error de captura: es que el conteo va atrasado.

### P5 — Evidencia fotográfica de mermas

§6.2 la pide **opcional**. **Recomiendo diferirla** y decir la razón: no existe almacenamiento de archivos
en el proyecto, y §10 lo lista como transversal de la Iteración 11 (archivos seguros con URL firmada). Traer
subida de archivos aquí significa decidir disco vs. S3, límites, tipos permitidos y borrado — una iteración
de inventarios no es el sitio para estrenar eso.

La columna `evidence_path` se puede crear ahora y quedarse `NULL`. **No lo recomiendo**: una columna que
nadie escribe es una promesa a medias. Mejor agregarla cuando exista el almacenamiento.

### P6 — Mínimos y sugerencia de reorden

**Recomiendo no en esta iteración.** Es tentador porque el dato está a mano —`article_stocks.quantity`
comparado con un mínimo— pero un mínimo útil depende del consumo promedio, y el consumo promedio necesita
historia que todavía no existe. Un mínimo capturado a mano en trescientos artículos es un campo que nadie
llena.

Con dos semanas de kardex, el mínimo se puede **sugerir** en lugar de pedirse. Eso pertenece a Reportes
(Iteración 8).

### P7 — ¿Confirmar una recepción necesita su propio permiso?

Capturar una recepción es teclear; **confirmarla mueve inventario y escribe en el historial de costos**, que
es irreversible. Son acciones de naturaleza distinta y hoy comparten `purchasing.receipts.create`.

**Recomiendo un permiso nuevo: `purchasing.receipts.confirm`.** Es la primera vez en el proyecto que se
agrega un permiso al catálogo cerrado, y D72 lo permite explícitamente —cada iteración agrega los de su
módulo—; lo que exige es que la matriz de autorización lo cubra, y el candado de D128 lo obliga.

El caso de uso concreto: el almacenista recibe y captura; el encargado confirma. Con un solo permiso, quien
captura confirma, y la recepción de compra es exactamente donde entra el faltante que nadie revisó.

Si prefieres no agregarlo, la alternativa es que confirmar exija autorización por PIN sobre el umbral, como
las mermas. Es más ceremonia por operación y no separa los dos papeles.

### P8 — ¿La producción es un documento o un movimiento?

**Recomiendo documento** (`production_orders`). Un movimiento suelto no puede guardar **qué receta se usó**,
y sin eso un lote producido en marzo se explicaría con la receta de agosto. El documento además agrupa el
consumo de N insumos con la entrada del producible en una unidad reversible: cancelar una producción es
cancelar un documento, no perseguir seis movimientos.

---

## 12. Resumen: nueve tablas nuevas

| Tabla | Módulo | Inmutable |
|---|---|---|
| `stock_movements` | Inventory | **Sí** (§7) |
| `article_stocks` | Inventory | No (proyección) |
| `article_lots` | Inventory | No |
| `waste_reasons` | Inventory | No |
| `stock_counts` | Inventory | Cerrado, sí |
| `stock_count_lines` | Inventory | Cerrado, sí |
| `transfers` | Inventory | No (máquina de estados) |
| `transfer_lines` | Inventory | No |
| `production_orders` | Inventory | Completada, sí |
| `suppliers` | Purchasing | No |
| `purchase_receipts` | Purchasing | Confirmada, sí |
| `purchase_receipt_lines` | Purchasing | Confirmada, sí |
| `supplier_prices` | Purchasing | **Sí** |

Trece, no nueve — el conteo de la hoja de ruta se queda corto porque las líneas de documento no se
contaban. Y una columna nueva en `Catalog`: `articles.tracks_lots`.

---

## 13. Lo que necesito de ti

1. **Aprobar o corregir el diseño** de las secciones 2 y 3 (entidades, FKs, índices, estados).
2. **P2 es la decisión que más cambia el código**: último costo o promedio ponderado. Después de tener
   movimientos, cambiarla exige reconstruir valuaciones.
3. **P7 agrega un permiso al catálogo cerrado.** Es la primera vez y conviene que sea explícito.
4. El resto de las P tienen recomendación y se pueden aprobar en bloque si te parecen bien.

Sin tu aprobación no escribo ninguna migration.
