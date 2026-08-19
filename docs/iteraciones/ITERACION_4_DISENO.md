# Iteración 4 — POS núcleo · Diseño técnico detallado

> **Estado: PROPUESTA. No se escribe una sola migración hasta que la apruebes.**

**Alcance de la hoja de ruta (§14):** órdenes / comandas / cuentas, sesiones de caja, pagos y propinas,
impresión (trabajos + agente).

**Cuatro decisiones ya tomadas** al abrir la iteración, que este diseño da por firmes:

| # | Decisión | Consecuencia en este documento |
|---|---|---|
| D231 | Los eventos que cruzan módulos viven en el **shared kernel**, con datos primitivos | §5. Se migran los dos eventos existentes que ya cruzan |
| D232 | El **diario financiero mínimo** y el catálogo de **métodos de pago** se adelantan a esta iteración | §4. El corte sale del diario desde el primer día |
| D233 | La propina se atribuye al **mesero titular de la cuenta**, congelado en la línea de pago | §3.6 |
| D234 | El **rol activo se recuerda** y se reinicia al iniciar sesión | §8.1. Es del kernel, entra como paso 0 |

---

## 1. Las cuatro preguntas que definen la iteración

### 1.1 ¿Qué es una venta?

En este sistema **no hay una entidad «venta»**, y eso es deliberado (D28). Hay tres entidades con ciclos de
vida distintos, y confundirlas es el error clásico de los POS:

- **Orden** — lo que se pidió y debe prepararse. Vive hacia la cocina.
- **Comanda** — el fragmento de una orden ruteado a **un** área de preparación. Es lo que se imprime.
- **Cuenta** — lo que se cobra. Acumula N órdenes y recibe pagos.

La prueba de que deben estar separadas: una cuenta de cuatro personas que piden tres veces a lo largo de dos
horas tiene **una cuenta, tres órdenes y hasta nueve comandas** (cocina, barra, postres). Y al dividir la
cuenta en cuatro, las comandas ya impresas no cambian — porque describen algo que ya ocurrió.

### 1.2 ¿Qué es lo que se cobra, exactamente?

Un **item de cuenta**, y su precio se **congela al capturarlo**. No se lee del catálogo al cobrar: si alguien
sube el precio del café a media tarde, las cuentas abiertas siguen cobrando el precio con el que se pidió.
Esto ya es la regla del proyecto para costos (§7, historial inmutable) y aquí se aplica a lo que el cliente
va a pagar, que es donde más importa.

Se congelan en la línea: **precio unitario, tasa de IVA** (paso 2 de D150), **nombre del artículo**, y los
**modificadores con su precio**. El nombre también, porque un ticket reimpreso un mes después tiene que decir
lo que decía el original aunque el artículo se haya renombrado o dado de baja.

### 1.3 ¿Puede el inventario o el costo detener un cobro?

**No.** §6.2 es explícito: el POS nunca se bloquea por inventario, el descuento es asíncrono y las existencias
negativas están permitidas. Este diseño lo lleva más lejos: **nada de lo que ocurre fuera del POS puede
impedir un cobro**. Si el diario financiero falla al asentar, si la impresora está apagada, si la receta de un
platillo tiene un ciclo — la cuenta se cobra igual y el fallo queda registrado para repararse.

La lección es de la iteración 3: un oyente que lanzaba hizo que una confirmación de compra **mintiera**
diciendo que no había ocurrido (D220). En el POS eso sería peor: alguien pagó, tiene su cambio en la mano, y
la pantalla dice que no.

### 1.4 ¿De qué depende que el dinero cuadre?

De la **sesión de caja** y del **diario**. Sin sesión abierta no hay cobro (§6.3), y todo lo que mueve
efectivo pertenece a una sesión. El corte se **calcula** del diario y nunca se almacena como verdad paralela
(§6.5, ADR-004): la diferencia entre lo esperado y lo declarado es ella misma un movimiento tipado.

---

## 2. Módulo `Pos`

### 2.1 `pos_sessions` — la sesión de caja

Es la primera tabla porque **nada se puede cobrar sin ella**.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT PK | |
| `ulid` | CHAR(26) ascii_bin | único |
| `tenant_id` | FK `tenants` CASCADE | NOT NULL (ADR-002) |
| `branch_id` | FK `branches` RESTRICT | NOT NULL |
| `terminal_id` | FK `terminals` RESTRICT | NOT NULL. Una sesión es de **una** terminal |
| `series` / `folio` | CHAR(4) / UNSIGNED INT | Foliación por (tenant, sucursal, `pos_session`, serie) |
| `status` | ENUM | `open`, `precounted`, `closed` |
| `opening_float` | DECIMAL(12,2) | El fondo con el que se abre |
| `opened_by_membership_id` | FK `tenant_memberships` RESTRICT | NOT NULL |
| `opened_at` | TIMESTAMP | NOT NULL |
| `precounted_by_membership_id` / `precounted_at` | nullable | El precorte, ver §2.3 |
| `closed_by_membership_id` / `closed_at` | nullable | |
| `closing_notes` | VARCHAR(300) nullable | |
| `timestamps` | | |

**Constraints:**

- `CHECK (opening_float >= 0)`.
- **Una sola sesión abierta por terminal**, garantizada por la base y no por código: columna generada
  `open_terminal_key = IF(status <> 'closed', terminal_id, NULL)` con índice único. Es el patrón que la
  iteración 3 usó para el conteo abierto por almacén (D173), y por la misma razón: dos sesiones simultáneas en
  la misma caja producen dos cortes que se pisan.

**Índices:** `(tenant_id, branch_id, status)` para «¿qué cajas están abiertas ahora?», que es la consulta del
gerente; `(tenant_id, terminal_id, opened_at)` para el historial de una caja.

### 2.2 El precorte ciego

`pos.blind_precount` ya existe en el catálogo de configuración, por omisión **activado**. El precorte declara
el efectivo contado **sin ver lo esperado**, exactamente como el conteo físico de inventario (D174): si el
cajero lee «esperado $4,320» escribe 4320 y no cuenta.

Las cantidades declaradas viven en `pos_session_declarations` (§2.3) y no en la sesión, porque son **una por
método de pago** y hay dos momentos (precorte y corte).

### 2.3 `pos_session_declarations` — lo que el cajero declara

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `pos_session_id` | FK `pos_sessions` CASCADE | |
| `moment` | ENUM `precount`, `close` | |
| `payment_method_id` | FK `payment_methods` RESTRICT | |
| `declared_amount` | DECIMAL(12,2) | Lo que la persona dice que hay |
| `declared_by_membership_id` | FK | Quién declaró: puede no ser quien abrió |
| `declared_at` | TIMESTAMP | |

**Unique** `(pos_session_id, moment, payment_method_id)`: una declaración por método y momento.

**No lleva el esperado ni la diferencia.** Los dos se **calculan** del diario al vuelo (§4.3). Guardarlos sería
la verdad paralela que ADR-004 prohíbe, y además quedarían desactualizados en cuanto se asentara un movimiento
más.

### 2.4 `pos_accounts` — la cuenta

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid` | | |
| `tenant_id` | FK CASCADE | |
| `branch_id` | FK RESTRICT | NOT NULL |
| `series` / `folio` | | Foliación por (tenant, sucursal, `pos_account`, serie) |
| `kind` | ENUM `dine_in`, `takeout` | Sin `delivery` en v1; llega con e-commerce (9) |
| `status` | ENUM | `open`, `bill_requested`, `closed`, `paid`, `cancelled` |
| `label` | VARCHAR(60) nullable | El nombre libre de una cuenta de barra: «Barra 3», «Señor de lentes» |
| `waiter_membership_id` | FK `tenant_memberships` RESTRICT | El **titular** (D233). NOT NULL |
| `pos_session_id` | FK `pos_sessions` RESTRICT | nullable mientras no se cobra; NOT NULL al pagar |
| `opened_by_membership_id` | FK | Quién la abrió, que puede no ser el titular |
| `opened_at` | TIMESTAMP | |
| `bill_requested_at` / `closed_at` / `paid_at` / `cancelled_at` | nullable | |
| `cancelled_reason` | VARCHAR(300) nullable | |
| `subtotal`, `discount_total`, `vat_total`, `total` | DECIMAL(12,2) | Proyección, ver abajo |
| `paid_total`, `tip_total`, `change_total` | DECIMAL(12,2) | Proyección |
| `parent_account_id` | FK `pos_accounts` RESTRICT nullable | De qué cuenta salió al dividir |
| `version` | UNSIGNED INT default 0 | **Candado optimista** (§11 de la Arquitectura) |
| `timestamps` | | |

**La versión existe para que dos terminales no cobren la misma cuenta.** §11 de la Arquitectura lo pide por
nombre: «versión de cuenta verificada al pagar». Se incrementa en cada escritura de la cuenta y quien cobra
manda la versión que leyó; si no coincide, recibe **409** y vuelve a leer. Un lock pesimista sobre la cuenta
serviría igual contra el cobro doble, pero bloquearía al mesero que está capturando mientras el cajero mira la
pantalla — y en un POS táctil eso se nota.

**Los totales son proyección, no verdad.** Se recalculan de los items y de los pagos dentro de la misma
transacción que los modifica, igual que `article_stocks` se recalcula del kardex. La verdad son los items y las
líneas de pago; el total existe para no sumar veinte filas en cada listado del piso de venta.

**Constraints:** `CHECK (total >= 0)`, `CHECK (paid_total >= 0)`, `CHECK (tip_total >= 0)`. Y **no** hay un
CHECK que ate `status = 'paid'` a `paid_total >= total`: la cortesía es una venta en $0 legítima (§6.3) y un
descuento del 100 % también.

**Índices:** `(tenant_id, branch_id, status, opened_at)` — la consulta del piso es «cuentas abiertas de esta
sucursal»; `(tenant_id, pos_session_id)` para el corte; `(tenant_id, waiter_membership_id, paid_at)` para las
propinas de un mesero, que la iteración 5 liquidará.

### 2.5 `pos_orders` y `pos_order_items`

Una **orden** es un envío: lo que se capturó y se manda a preparar de una vez.

`pos_orders`: `id`, `ulid`, `tenant_id`, `pos_account_id` (FK RESTRICT), `sequence` (el número de la orden
dentro de la cuenta: 1, 2, 3…), `created_by_membership_id`, `sent_at` nullable, `timestamps`.

**Unique** `(pos_account_id, sequence)`.

`pos_order_items` — **la línea que se cobra**:

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `pos_order_id` | FK `pos_orders` CASCADE | |
| `pos_account_id` | FK `pos_accounts` RESTRICT | **Denormalizado a propósito**: mover un item entre cuentas cambia esta columna, y la orden se queda donde estaba porque describe lo que se preparó |
| `article_id` | FK `articles` RESTRICT | |
| `preparation_area_id` | FK `preparation_areas` RESTRICT nullable | El área que le tocó al comandar |
| `status` | ENUM `captured`, `commanded`, `preparing`, `served`, `cancelled` | §6.3 |
| `quantity` | DECIMAL(12,4) | Cuatro decimales: se puede vender media pizza |
| `article_name` | VARCHAR(120) | **Congelado** |
| `unit_price` | DECIMAL(12,2) | **Congelado**, IVA incluido (D30) |
| `vat_rate` | DECIMAL(5,2) | **Congelado** (paso 2 de D150) |
| `modifiers_total` | DECIMAL(12,2) | Suma de los extras, congelada |
| `discount_amount` | DECIMAL(12,2) default 0 | Lo que se descontó de esta línea |
| `line_total` | DECIMAL(12,2) | Generada STORED: `(quantity * (unit_price + modifiers_total)) - discount_amount` |
| `is_courtesy` | BOOLEAN default false | Cortesía: sí descuenta inventario (§6.3) |
| `cancelled_reason` / `cancelled_by_membership_id` / `cancelled_at` | nullable | |
| `cancellation_destination` | ENUM `none`, `waste`, `restock` nullable | Qué se hizo con la comida |
| `captured_by_membership_id` | FK | Quién la capturó |
| `timestamps` | | |

**Por qué `line_total` es columna generada:** el mismo argumento de `variance` y `balance_after` en la
iteración 3 — un total calculado en PHP y guardado se desincroniza en cuanto alguien escribe por otra vía. Y
`quantity * (unit_price + modifiers_total)` es la multiplicación de dinero donde se cuela el error de redondeo
(D134), así que la hace la base una sola vez.

**Constraints:** `CHECK (quantity > 0)`, `CHECK (unit_price >= 0)`, `CHECK (discount_amount >= 0)`, y
`CHECK (vat_rate >= 0 AND vat_rate <= 100)`.

**Índices:** `(tenant_id, pos_account_id, status)` — la cuenta en pantalla; `(tenant_id, preparation_area_id,
status)` para «¿qué falta en esta barra?».

### 2.6 `pos_order_item_modifiers` — los extras congelados

`id`, `ulid`, `tenant_id`, `pos_order_item_id` (CASCADE), `modifier_id` (FK RESTRICT), `modifier_name`
VARCHAR(80) **congelado**, `quantity` UNSIGNED SMALLINT (los 3 shots de D7), `extra_price` DECIMAL(12,2)
**congelado**, `timestamps`.

**Unique** `(pos_order_item_id, modifier_id)`.

Se congelan nombre y precio por lo mismo que en la línea: el ticket reimpreso debe decir lo que decía.

### 2.7 `pos_tickets` — comandas y tickets

Una **comanda** es un fragmento de orden ruteado a un área. Y un **ticket de cierre** y un **ticket final** son
documentos del mismo género: algo que se imprimió y de lo que hay que poder decir cuándo y quién.

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `kind` | ENUM `command`, `command_cancellation`, `bill_preview`, `final_receipt` | |
| `pos_account_id` | FK RESTRICT | |
| `pos_order_id` | FK RESTRICT nullable | Sólo las comandas |
| `preparation_area_id` | FK RESTRICT nullable | Sólo las comandas |
| `series` / `folio` | | Sólo `final_receipt` folia (§2.8) |
| `issued_by_membership_id`, `issued_at` | | |
| `reprint_count` | UNSIGNED SMALLINT default 0 | Reimprimir es auditado (permiso `printing.jobs.reprint`) |
| `timestamps` | | |

`pos_ticket_items`: la relación N:N con los items que entraron en ese documento (`pos_ticket_id`,
`pos_order_item_id`, y la `quantity` que se comandó, porque se puede comandar parte de una línea).

### 2.8 Qué folia y qué no

Folían con `DocumentNumberAllocator` (sin huecos, bajo lock):

| Documento | Tipo de folio | Por qué |
|---|---|---|
| `pos_sessions` | `pos_session` | El corte se identifica por su número |
| `pos_accounts` | `pos_account` | Es el número que el cliente ve en la cuenta |
| `pos_tickets` de tipo `final_receipt` | `pos_receipt` | Es el comprobante; será el folio facturable (ADR-005) |

**No folían** las órdenes, las comandas ni los tickets de cierre: la orden se identifica por su `sequence`
dentro de la cuenta, y una comanda es un papel de cocina, no un documento con valor.

Y hay un límite que conviene decir ahora: la foliación **serializa** por (sucursal, tipo, serie). A
1 000 cuentas/día en el tenant más pesado son ~4/minuto en hora pico, y el lock dura milisegundos. Pero si
alguna vez cada **item** foliara, el POS se detendría. Es la razón de la tabla de arriba.

---

## 3. Cobro, pagos y propinas

### 3.1 `payment_methods` — el catálogo (módulo `Finance`)

Adelantado a esta iteración por D232, porque un pago sin método no se puede registrar.

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `code` | CHAR(30) ascii_bin | único por tenant |
| `name` | VARCHAR(60) | |
| `kind` | ENUM `cash`, `card`, `transfer`, `customer_credit`, `custom` | |
| `affects_cash_drawer` | BOOLEAN | §6.3. El efectivo sí; la tarjeta no |
| `is_system` | BOOLEAN | Los tres del sistema no se borran ni se renombran |
| `requires_reference` | BOOLEAN | Un transferencia sin referencia no se puede conciliar |
| `allows_change` | BOOLEAN | Sólo el efectivo da cambio |
| `status` | ENUM `active`, `inactive` | Se da de baja, no se borra: los pagos lo citan |
| `sort_order` | UNSIGNED SMALLINT | El orden de los botones en la caja |

Se siembran tres al provisionar el tenant: **efectivo** (afecta cajón, da cambio), **tarjeta** y
**transferencia** (con referencia). `customer_credit` **no** se siembra en esta iteración: necesita Clientes y
el saldo por cliente (7). El enum lo declara para que la columna no cambie después.

### 3.2 `pos_payments` — inmutable

Es una tabla **append-only** (§7 lista `pagos` entre los inmutables). Corregir un pago es registrar su reversa,
no editarlo.

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `pos_account_id` | FK RESTRICT | |
| `pos_session_id` | FK RESTRICT | NOT NULL. Todo pago pertenece a una sesión (§6.3) |
| `payment_method_id` | FK RESTRICT | |
| `amount` | DECIMAL(12,2) | Lo que se aplica a la cuenta |
| `tendered_amount` | DECIMAL(12,2) nullable | Lo que entregó el cliente (sólo efectivo) |
| `change_amount` | DECIMAL(12,2) default 0 | Calculado y **registrado** (§6.3) |
| `tip_amount` | DECIMAL(12,2) default 0 | **Por línea de pago** (§6.3) |
| `tip_membership_id` | FK `tenant_memberships` RESTRICT nullable | **A quién se le atribuye** (D233), congelado aquí |
| `reference` | VARCHAR(60) nullable | Autorización de tarjeta, folio de transferencia |
| `charged_by_membership_id` | FK | Quién cobró — puede no ser el titular |
| `reverses_payment_id` | FK `pos_payments` RESTRICT nullable | La reversa enlaza a su original |
| `occurred_at` | TIMESTAMP | |
| `created_at` | | Sin `updated_at`: es inmutable |

**Constraints:** `CHECK (amount <> 0)` —una reversa es negativa—, `CHECK (tip_amount >= 0)`,
`CHECK (change_amount >= 0)`.

**Índices:** `(tenant_id, pos_session_id, payment_method_id)` — el corte agrupa exactamente por eso;
`(tenant_id, pos_account_id)`; `(tenant_id, tip_membership_id, occurred_at)` para la liquidación de propinas.

### 3.3 La propina, con nombre y apellido

D233 decidió: la cuenta tiene un **titular** (`pos_accounts.waiter_membership_id`) y cada línea de pago
**congela** en `tip_membership_id` a quién se le atribuye su propina, en el momento del cobro.

Lo que eso compra: **una operación posterior no reescribe propinas ya pagadas**. Si a las 22:00 se juntan dos
cuentas, las propinas cobradas a las 21:00 siguen siendo de quien las ganó. Sin congelarlas, juntar cuentas
movería dinero de una persona a otra sin que nadie lo hubiera decidido.

Al **dividir**, cada subcuenta hereda el titular de la original. Al **juntar**, manda el titular de la cuenta
destino, y el cambio queda escrito en el historial de la operación (§3.5).

### 3.4 Descuentos y cortesías — la zona de máxima auditoría

`pos_discounts`, append-only:

`id`, `ulid`, `tenant_id`, `pos_account_id`, `pos_order_item_id` nullable (null = descuento de cuenta),
`kind` ENUM `percentage`/`amount`/`courtesy`, `value` DECIMAL(12,2), `resulting_amount` DECIMAL(12,2),
`reason` VARCHAR(300) **NOT NULL**, `applied_by_membership_id`, `authorized_by_membership_id` nullable,
`created_at`.

Tres cosas que no se negocian aquí, porque §6.3 las llama «zona de máxima auditoría»:

1. **El motivo es obligatorio.** Mismo argumento que las mermas (D27): un descuento sin motivo es dinero que
   nadie puede explicar.
2. **Exige PIN** (ADR-008), con `pos.discounts.apply_item` / `apply_account` / `courtesy`. El actor real queda
   registrado, distinto de quien tiene la terminal abierta.
3. **`resulting_amount` se calcula en el servidor.** Un porcentaje enviado por el cliente se aplica en el
   servidor y el monto resultante se congela: si el cliente calculara el monto, un 10 % podría llegar como
   cualquier cifra.

Una **cortesía** es una venta en $0 que **sí descuenta inventario** (§6.3). Se modela como descuento de tipo
`courtesy` y la línea queda con `is_courtesy = true`, para que el consumo de insumos ocurra igual.

### 3.5 Operaciones de cuenta, historizadas

§6.3 exige que dividir, mover y juntar queden registradas. `pos_account_operations`, append-only:

`id`, `ulid`, `tenant_id`, `kind` ENUM `split`/`merge`/`move_items`/`reopen`,
`source_account_id`, `target_account_id` nullable, `performed_by_membership_id`,
`authorized_by_membership_id` nullable, `detail_count` UNSIGNED SMALLINT, `created_at`.

Y `pos_account_operation_items` con los items que se movieron (`operation_id`, `pos_order_item_id`,
`from_account_id`, `to_account_id`).

Sin esto, mover un item entre cuentas es indistinguible de haberlo capturado allí desde el principio — y ése
es exactamente el hueco por el que se va la mercancía en un bar.

### 3.6 La máquina de estados de la cuenta

```
open ──► bill_requested ──► closed ──► paid
  │            │
  └────────────┴──────────► cancelled
```

- `open → bill_requested`: imprime el **ticket de cierre**. Si `pos.lock_items_on_bill_request` está activo, no
  se capturan más items.
- `bill_requested → closed`: el total queda fijado. Se puede volver a `open` con
  `pos.accounts.reopen` (permiso propio, auditado).
- `closed → paid`: cuando `paid_total >= total`. Emite el **ticket final** con desglose de pagos y propina.
- `→ cancelled`: sólo sin pagos aplicados. Una cuenta con pagos se corrige por reversa, no se cancela.

Las transiciones permitidas las expone el servidor en `allowed_next`, como en las transferencias (§2.7 del diseño de
la iteración 3): el cliente no lleva su propia copia de la máquina.

### 3.7 La máquina de estados del item

```
captured ──► commanded ──► preparing ──► served
    │             │             │
    └── borrado    └─────────────┴──────► cancelled
```

- **Cancelar no comandado = borrar** (§6.3). No hay rastro porque no ocurrió nada: nadie preparó nada y nadie
  vio el papel.
- **Cancelar comandado** exige **motivo + PIN** (`pos.items.cancel_commanded`), emite una **comanda de
  cancelación** al área, y pide el **destino**: `waste` si ya se preparó (genera merma con su motivo) o
  `restock` si no se tocó.

---

## 4. Módulo `Finance` — el diario mínimo (D232)

### 4.1 `financial_movements` — inmutable, tipado, con origen (ADR-004)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `type` | ENUM (catálogo) | `sale`, `payment`, `change`, `tip`, `discount`, `courtesy`, `withdrawal`, `opening_float`, `count_difference`, `reversal` |
| `pos_session_id` | FK RESTRICT nullable | NOT NULL para todo lo que toca caja |
| `payment_method_id` | FK RESTRICT nullable | |
| `affects_cash_drawer` | BOOLEAN | **Copiado** del método al asentar, no leído después: si mañana alguien cambia la bandera del método, los cortes de ayer no deben cambiar |
| `amount` | DECIMAL(12,2) | Con signo |
| `source_type` / `source_ulid` | VARCHAR(120) / CHAR(26) | El documento origen, por ULID y no por llave interna (D151) |
| `actor_membership_id` | FK RESTRICT | |
| `reverses_movement_id` | FK propio RESTRICT nullable | La corrección enlaza a su original |
| `occurred_at` | TIMESTAMP | |
| `created_at` | | Sin `updated_at` |

**Índices:** `(tenant_id, pos_session_id, type)` — el corte; `(tenant_id, branch_id, occurred_at)` — los
reportes de la iteración 8; `(tenant_id, source_type, source_ulid)` — «¿qué asentó este documento?», que es la
consulta de auditoría.

**Idempotencia:** llave única `(tenant_id, source_type, source_ulid, type)`. Re-despachar el evento de un pago
no duplica su asiento, que es la regla de jobs idempotentes de CLAUDE.md aplicada al dinero.

### 4.2 Quién escribe en el diario

**Nadie directamente.** El POS emite eventos del kernel (§5) y `Finance` los asienta con un oyente. La regla 3
de §2 no admite matices aquí, y es la que hace que el diario sea auditable: hay **un** camino de escritura.

### 4.3 El corte, calculado

Un corte no es una tabla. Es una **consulta** sobre `financial_movements` agrupada por método de pago:

```
esperado(método) = opening_float (si efectivo) + Σ pagos − Σ cambios − Σ retiros
declarado(método) = pos_session_declarations donde moment = 'close'
diferencia = declarado − esperado
```

Y la diferencia, si no es cero, **se asienta** como movimiento de tipo `count_difference` (§6.5: «Diferencia =
movimiento tipado»). Así el diario cuadra consigo mismo y la diferencia queda con nombre, monto y actor.

### 4.4 Retiros parciales

`pos_session_withdrawals`: `id`, `ulid`, `tenant_id`, `pos_session_id`, `amount`, `reason` VARCHAR(300),
`performed_by_membership_id`, `authorized_by_membership_id` nullable, `created_at`. Append-only.

Exige `pos.sessions.withdraw` y PIN. Asienta un movimiento `withdrawal` que afecta cajón. El **depósito** con
referencia bancaria (D38) es de la iteración 5: aquí el dinero sale de la caja y queda en un limbo declarado,
que es honesto — decir que se depositó sin registrar dónde sería peor.

---

## 5. Eventos y fronteras (D231)

### 5.1 Los contratos viven en el kernel

Nuevo espacio: `app/Modules/Shared/Domain/Events/`. Un evento que cruza módulos lleva **sólo primitivos**:
ULIDs, montos como cadena, enteros. Nunca un modelo Eloquent.

Dos razones, y la segunda importa más que la arquitectónica:

1. Nadie declara depender de un módulo operativo, así que la regla 2 de §2 se respeta tal como está escrita.
2. **Los eventos se serializan a la cola.** Pasar un modelo a un job y recargarlo al otro lado es una fuente de
   bugs conocida: el modelo pudo cambiar entre el despacho y el consumo. Con ULIDs y montos, el oyente lee el
   estado que hay cuando actúa, o falla ruidosamente si el documento ya no existe.

### 5.2 Los eventos de esta iteración

| Evento | Lo emite | Lo escucha | Efecto |
|---|---|---|---|
| `PosOrderCommanded` | `Pos` | `Printing` | Crea los trabajos de impresión, uno por área |
| `PosItemsCancelled` | `Pos` | `Printing`, `Inventory` | Comanda de cancelación; merma si el destino es `waste` |
| `PosAccountPaid` | `Pos` | `Finance`, `Inventory`, `Printing` | Asienta pagos, propina y cambio; **descuenta insumos en cola**; imprime el ticket final |
| `PosSessionOpened` / `PosSessionClosed` | `Pos` | `Finance` | Asienta el fondo y la diferencia de corte |
| `PosWithdrawalRegistered` | `Pos` | `Finance` | Asienta el retiro |
| `PosDiscountApplied` | `Pos` | `Finance` | Asienta el descuento o la cortesía |

**El único asíncrono es el descuento de inventario** (§6.2). Los demás corren después del commit en la misma
petición, porque quien cobra necesita ver su ticket. Y **ningún oyente puede tumbar el cobro**: el fallo se
registra y no se propaga, con la lección de D220 aplicada desde el diseño y no después.

### 5.3 Migración de los dos eventos que ya cruzan

`PurchaseReceiptConfirmed` (lo escuchan `Inventory` y `Costing`) y `ArticleCostChanged` (lo escucha `Catalog`
para el precio sugerido) pasan al kernel con primitivos. Los que **no** cruzan —`StockMovementRecorded`,
`RecipeChanged`, `TenantProvisioned`— se quedan donde están: son internos del módulo o del kernel.

Al terminar, `Inventory` y `Costing` dejan de declarar `depends_on: ['Purchasing']`, y el candado de fronteras
vigila más, no menos.

### 5.4 El descuento de inventario por venta

El único camino asíncrono, y el más delicado del sistema. Al pagarse una cuenta, un job por cuenta:

1. Por cada item **no cancelado** (incluidas las cortesías), resuelve qué consumir:
   - Artículo **inventariable** y no producible → se consume él mismo.
   - Artículo **producible** → se explota su receta con `ResolveProductionConsumption`, que ya aplica
     rendimiento y conversión de unidades.
   - **Modificadores** con receta → se explotan igual (la tabla `recipes` ya tiene `modifier_id`).
2. El almacén es el del **área de preparación** que atendió el item (`preparation_areas.warehouse_id`, §3). Un
   item sin área —una cerveza que el mesero saca de la nevera— usa el almacén de la sucursal.
3. Registra los movimientos `sale_consumption` por la puerta única del kardex, con llave de idempotencia
   `pos_account:{ulid}:item:{ulid}:{component_ulid}`.

**Existencias negativas permitidas** (§6.2) y el job **nunca reintenta hacia el cobro**: si falla, la cuenta
sigue pagada y el job queda para reparar. Re-despacharlo no duplica nada.

---

## 6. Módulo `Printing`

### 6.1 `print_jobs`

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `pos_ticket_id` | FK RESTRICT | Qué documento se imprime |
| `printer_target` | VARCHAR(80) | El destino, resuelto del área o de la terminal |
| `preparation_area_id` | FK RESTRICT nullable | |
| `terminal_id` | FK RESTRICT nullable | Los tickets se imprimen en la caja |
| `status` | ENUM `pending`, `claimed`, `printed`, `failed`, `cancelled` | |
| `payload` | JSON | **La excepción autorizada** (CLAUDE.md): trabajos de impresión y bitácora |
| `attempts` | UNSIGNED SMALLINT default 0 | |
| `claimed_by_agent` | VARCHAR(80) nullable | Qué agente lo tomó |
| `claimed_at`, `printed_at`, `failed_at` | nullable | |
| `last_error` | VARCHAR(300) nullable | |
| `timestamps` | | |

**Índice** `(tenant_id, branch_id, status, id)` — la consulta del agente es «dame lo pendiente de esta
sucursal», y el `id` al final hace el orden determinista.

### 6.2 El agente, y qué entra de él en esta iteración

El `.module.md` de `Printing` ya lo dice: el **puente Flutter** es v1 y el agente de escritorio Windows es la
segunda implementación. Así que en esta iteración entra el **contrato**, que es lo que no se puede improvisar
después:

- `GET /print-jobs/next` — el agente reclama trabajos (`pending → claimed`) con lock, en lote.
- `POST /print-jobs/{job}/printed` y `/failed` — confirma o falla, idempotente.
- `POST /print-jobs/{job}/reprint` — auditado, con `printing.jobs.reprint`.
- Autenticación por **token de agente** ligado a una sucursal, con su propio scope: un agente no es un usuario.

**No entra** el ejecutable: ni instalador, ni descubrimiento de impresoras, ni servicio de Windows. Sí entra un
**cliente de prueba** que consuma el contrato de punta a punta e imprima a archivo, porque sin él el contrato
no está verificado — y verificarlo es lo que hace que el agente real sea un trabajo de un día.

---

## 7. Configuración nueva

| Llave | Tipo | Por omisión | Alcance | Por qué |
|---|---|---|---|---|
| `pos.tip_suggestions` | Enum | `10,15,20` | Sucursal | Los botones de propina sugerida |
| `pos.require_session_to_open_account` | Bool | `false` | Sucursal | Abrir cuenta sin sesión sí; **cobrar** no, y eso no es configurable |
| `pos.cancellation_default_destination` | Enum `waste`/`restock` | `waste` | Sucursal | §6.3: destino configurable |
| `pos.account_label_required` | Bool | `false` | Sucursal | Un bar quiere nombre en cada cuenta; una fonda no |
| `printing.job_max_attempts` | Int | `5` | Tenant | Cuándo dejar de reintentar |
| `printing.reprint_requires_pin` | Bool | `true` | Sucursal | Reimprimir un ticket final es material para un fraude |

Las tres que ya existen (`pos.blind_precount`, `pos.lock_items_on_bill_request`,
`pos.takeout_payment_timing`) se **consumen** por primera vez en esta iteración.

---

## 8. Permisos

### 8.1 El rol activo se recuerda (D234)

Paso 0 de la implementación, y va en el kernel: columna `tenant_memberships.last_active_role_id` (FK
`roles` SET NULL), escrita por `ResolveTenantContext` cuando el rol cambia, exactamente como ya se escribe
`last_active_branch_id`. Se **reinicia al iniciar sesión**: el rol por omisión gana al autenticarse.

Sin esto, cada acción del POS que dependa del rol activo se comporta de forma distinta según cómo se llegó a la
pantalla, y eso en una terminal de caja no es aceptable.

### 8.2 Los permisos ya declarados que esta iteración consume

Los veinte de `pos.*` y los tres de `printing.*` **ya están en el catálogo cerrado** y no se agrega ninguno,
con dos excepciones que sí hacen falta:

| Permiso nuevo | Por qué |
|---|---|
| `finance.payment_methods.manage` | El tenant configura sus métodos custom con su bandera de cajón |
| `finance.journal.view` | Leer el diario. Separado de cobrar: quien cobra no necesita ver el diario del negocio |

Dos permisos de `pos.*` **quedan sin ruta a propósito** y se documentan como tal en la revisión:
`pos.credit.charge_to_customer` (necesita Clientes) y `pos.takeout.manage` si el flujo de para llevar se
reduce (§9).

### 8.3 Qué exige PIN

`pos.discounts.*`, `pos.items.cancel_commanded`, `pos.cash_drawer.open`, `pos.accounts.reopen`,
`pos.sessions.withdraw`. Todas responden **409 `authorization_required`** con el permiso que hace falta, y el
diálogo del frontend ya existe desde la iteración 3.

---

## 9. Qué NO entra

Declarado, con la deuda que genera:

| Fuera | Por qué | Deuda |
|---|---|---|
| **Mesas y piso de venta** | `Floor` es la iteración 6 | La 4 opera con cuentas de barra (`label`) y para llevar. La 6 agrega `table_id` y la liberación de mesa al pagar |
| **Promociones** | `Promotions` es la 7 | Los descuentos manuales sí entran; el catálogo de tipos (happy hour, NxM) no |
| **Crédito a clientes** | Necesita `Customers` (7) y saldo | El `kind` del método existe; no se siembra |
| **Cliente en la cuenta** | `Customers` es la 7 | La cuenta tiene `label` de texto libre; la 7 agrega `customer_id` |
| **Gastos, depósitos, liquidación de propinas** | Iteración 5 | El diario ya los admite: son tipos nuevos, no tablas nuevas |
| **Tiempo real en el piso** | Reverb es la 6 | El POS de la 4 refresca por petición |
| **Agente de impresión ejecutable** | Segunda implementación | Contrato verificado con cliente de prueba |
| **CFDI** | ADR-005, iteración 7 | El ticket final ya folia, y ése será el folio facturable |

---

## 10. Pruebas (Definition of Done)

Además de lo que CLAUDE.md exige siempre:

1. **Aislamiento de tenant** de `Pos`, `Finance` y `Printing` **por los caminos reales**, como en el paso 10 de
   la iteración 3: no basta consultar el modelo, hay que intentar llegar por cada FK.
2. **Concurrencia** (suite propia): dos terminales no abren dos sesiones en la misma caja; dos cobros
   simultáneos de la misma cuenta no la pagan dos veces (candado optimista por versión de cuenta, §11 de la
   Arquitectura); dos agentes no reclaman el mismo trabajo de impresión.
3. **Idempotencia**: re-despachar `PosAccountPaid` no duplica asientos ni movimientos de inventario.
4. **Ningún oyente tumba el cobro**: prueba que hace fallar cada oyente y verifica que la cuenta queda pagada,
   el ticket existe y el fallo está en el log.
5. **Autorización**: matriz permiso × contexto para descuentos, cancelación comandada, cajón, reapertura y
   retiro, con PIN de otra persona y actor real en la bitácora.
6. **El corte cuadra**: propiedad verificada sobre una sesión con pagos, cambios, propinas, descuentos,
   cortesías y un retiro — esperado del diario = suma de sus partes.
7. **Los congelados no se mueven**: cambiar el precio del catálogo, el nombre del artículo y la tasa de IVA
   **después** de capturar, y verificar que la línea y el ticket no cambian.
8. **Verificación en navegador** de las pantallas, obligatoria (§11): capturar, comandar, dividir, cobrar con
   dos métodos y propina, cerrar caja con diferencia.

---

## 11. Orden de implementación propuesto

Trece pasos. Cada uno es un commit con sus pruebas, y los tres primeros no tocan el POS.

| # | Paso | Por qué en ese lugar |
|---|---|---|
| 0 | Rol activo persistente (D234) | Es del kernel y todo lo demás depende de él |
| 1 | Contratos de evento en el kernel (D231) + migración de los dos existentes | Antes de emitir seis eventos nuevos |
| 2 | `payment_methods` + siembra al provisionar | Sin método no hay pago |
| 3 | `financial_movements` + el oyente que asienta | Sin diario no hay corte |
| 4 | `pos_sessions` + apertura/cierre + retiros | Sin sesión no hay cobro |
| 5 | `pos_accounts` + `pos_orders` + `pos_order_items` con los congelados | El corazón |
| 6 | Comandar: `pos_tickets` + áreas + máquina de estados del item | |
| 7 | `print_jobs` + contrato del agente + cliente de prueba | Comandar sin imprimir es la mitad |
| 8 | Cobro: `pos_payments`, propina, cambio, ticket final | |
| 9 | Descuentos y cortesías con PIN | Zona de máxima auditoría, sobre un cobro que ya funciona |
| 10 | Operaciones de cuenta: dividir, mover, juntar, reabrir | Necesitan items y pagos ya existentes |
| 11 | Descuento de inventario por venta, en cola | El único asíncrono; se prueba con worker |
| 12 | Corte y precorte ciego | Cierra el circuito del dinero |
| 13 | UI del POS y de la caja, verificada en navegador | |

---

## 12. Resumen: quince tablas nuevas

| Módulo | Tablas |
|---|---|
| `Pos` | `pos_sessions`, `pos_session_declarations`, `pos_session_withdrawals`, `pos_accounts`, `pos_orders`, `pos_order_items`, `pos_order_item_modifiers`, `pos_tickets`, `pos_ticket_items`, `pos_payments`, `pos_discounts`, `pos_account_operations`, `pos_account_operation_items` |
| `Finance` | `payment_methods`, `financial_movements` |
| `Printing` | `print_jobs` |

Dieciséis, contando bien. Cinco son **append-only**: `pos_payments`, `pos_discounts`,
`pos_account_operations`, `pos_session_withdrawals` y `financial_movements`. Todas se declaran en la lista de
inmutables de §7 de la Arquitectura, que tiene candado en las dos direcciones.

---

## 13. Lo que necesito de ti

**Aprobación explícita del diseño** antes de la primera migración, y en particular de estas cinco cosas, que
son las que no puedo decidir solo:

1. **El alcance recortado** de §9 — sobre todo que el POS de esta iteración **no tenga mesas**, y se pruebe con
   cuentas de barra y para llevar.
2. **Quince tablas y trece pasos** como una sola iteración, sin partirla en dos entregas.
3. Que **`pos_accounts.waiter_membership_id` sea NOT NULL**: toda cuenta tiene un titular, incluida una de
   barra que abrió el propio cajero. Si un negocio no quiere meseros, el titular es quien cobra.
4. Que **una cuenta con pagos no se pueda cancelar**, sólo corregir por reversa.
5. Que el **agente de impresión** se quede en contrato + cliente de prueba, sin ejecutable.

Y una pregunta que puede cambiar el paso 5: **¿el para llevar necesita numeración visible propia** —el «34» que
grita el mostrador— o basta el folio de la cuenta? §6.3 pide «numeración visible», y un folio de cuenta de
cinco cifras no sirve para gritarlo. Si hace falta, es una columna más y un contador por día y sucursal.
