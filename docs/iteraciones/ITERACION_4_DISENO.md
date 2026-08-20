# Iteración 4 — POS completo · Diseño técnico detallado

> **Estado: APROBADO. Tandas A y B completas; Tanda C en curso — paso 19 entregado.**
>
> **Paso 0:** el rol activo se recuerda y se reinicia al iniciar sesión (D234). Dos de mis pruebas pasaban por la razón
> equivocada: `withHeaders()` de Laravel persiste las cabeceras, así que la «petición sin cabecera» seguía llevándola.
>
> **Paso 1:** el contrato de eventos entre módulos en el kernel, con su candado. Corregido al implementar (D236): sólo
> **un** evento cruza módulos, no dos, y no se migra — su enlace de vuelta es atómico y romperlo costaría más que
> mantener la excepción declarada.
>
> **Paso 2:** `printers`, el ruteo a áreas y terminales, y la infraestructura operativa sembrada en el negocio de
> demostración — que no la tenía, así que el punto de venta no se podía demostrar en absoluto.
>
> **Paso 3:** `payment_methods` con los cuatro del sistema sembrados al alta. Nace el módulo `Finance`.
>
> **Paso 4:** el diario inmutable, su única puerta de escritura y las categorías de gasto. La lista de inmutables de §7
> queda completa. **Pendiente del paso 6:** la FK de `pos_session_id`.
>
> **Paso 5:** el salón — planos, zonas y mesas, con la unión temporal (D32). La tabla se llama `restaurant_tables` y no
> `tables`: choca con el vocabulario de MySQL. Y la suite pasó a correr en **paralelo**: de 20 minutos a 6, con un grupo
> `serial` declarado para las dos clases que salen del marco de `RefreshDatabase`.
>
> **Paso 6:** la sesión de caja, con los **primeros eventos del kernel** (D231) y el **primer oyente** de `Finance`. La FK
> pendiente de `pos_session_id` queda cerrada — y al cerrarla destapó que las pruebas del diario usaban un id de sesión
> inventado. El contrato del 409 `authorization_required` se movió al kernel: `Pos` lo necesitaba y estaba en
> `Inventory`.

> **Paso 7:** cuentas, órdenes e items con los **congelados** (precio, nombre, tasa de IVA y modificadores). Tres cosas
> aparecieron al implementarlo: el IVA se extraía **truncando** en lugar de redondeando —un centavo por renglón, siempre
> hacia abajo, en el número que el ticket desglosa (D237, con candado nuevo)—; la relación a la mesa no puede llamarse
> `table()` porque `$table` es una propiedad de Eloquent y devolvía la cadena `'pos_accounts'`; y el negocio de
> demostración no tenía **personal** con quien operar el punto de venta, sólo al dueño.
>
> Y el candado de fronteras destapó la más interesante: `Pos` escribía `restaurant_tables.status` directamente, leía un
> ajuste `floor.*` y deshacía las uniones del salón — un módulo implementando las reglas de otro, con un comentario mío
> que lo racionalizaba. Nace `Floor\Application\TableOccupancy` (D239), y al moverlo apareció que **nada** ponía la mesa
> en «cuenta solicitada», un estado que §6.4 pinta en la vista de piso.

> **Paso 8:** comandar — `pos_tickets`, el ruteo por área y la máquina de estados del item. El diseño no decía **de
> dónde sale el área de un artículo**, y la respuesta obvia —una columna en `articles`— está mal: las áreas son por
> sucursal, así que en un negocio de dos locales las comandas del segundo saldrían por la impresora del primero (D240).
> La frontera del PIN al cancelar es «ya lo comandaron», no el monto (D242). Y `actingAsSpa` arrastraba la sesión entre
> peticiones, lo que hacía imposible autenticarse como un segundo usuario — con el efecto de que varias pruebas de
> autorización preparaban al usuario ajeno por modelos y nunca ejercitaban su camino HTTP (D243).
>
> Además, `SQLSTATE 1615` dejó de ser un misterio: 770 tablas en 10 esquemas contra un `table_definition_cache` de 600.
> Está diagnosticado en `docs/ENTORNO_LOCAL.md` §8, con el arreglo inmediato y el de fondo.

> **Paso 9:** impresión. Nace el módulo `Printing` con `print_jobs`, `print_agents`, el contrato del agente y el cajón
> de dinero. Un agente **no es un usuario** y tiene autenticación propia (D244): colgarle una membresía habría sido lo
> cómodo y le abriría la API entera a un proceso que corre sin vigilancia en una computadora de cocina. Reclamar es
> exclusivo, reportar es idempotente y un fallo **no** se reintenta solo (D245). Un área sin impresora no tumba la venta
> (D246). Entra el contrato y un cliente que lo ejercita; no entra el ejecutable (D249).
>
> Y el arnés de pruebas falló por **tercera vez** en esta iteración, con la misma familia de causa: el `Referer` de un
> `actingAsSpa` anterior hacía que Sanctum tratara la petición del agente como si fuera un navegador, y el 401 apuntaba
> tres capas más allá de donde venía (D250).

> **Paso 10:** el cobro. `pos_payments` inmutable y multi-línea, propina congelada con nombre, cambio calculado y
> guardado, y el ticket final —el único papel del POS que folia—. La propina **no** entra en el cambio (D251), que es el
> error más caro de este servicio porque se cometería a favor del cliente y en contra del cajero todas las noches.
>
> Dos defectos míos que la infraestructura destapó: la cuenta no quedaba atada a la caja (`pos_session_id = 0`, D252) y
> el cambio se asentaba en positivo — el enum llevaba dos pasos avisando de que ése era «el error más fácil de cometer».
> El diario ahora lo **rechaza** (D253). Y escribir el oyente de Finanzas leyendo `pos_payments` habría cerrado un ciclo
> `Pos ↔ Finance`, así que las líneas viajan en el evento (D255).

> **Paso 11:** descuentos y cortesías, la zona de máxima auditoría. El PIN se pide **siempre**, incluso a quien tiene
> el permiso: el permiso lo tiene la sesión —una terminal abierta que cualquiera puede tocar— y el PIN lo tiene la
> persona (D257). Se guardan las dos, en columnas distintas, porque el patrón que el reporte de §9 busca es «el mismo
> mesero pidiendo autorización veinte veces por turno». Y el monto siempre lo calcula el servidor, sobre la base viva
> (D258).

> **Paso 12:** dividir, mover y juntar, todo historizado — sin ese historial, mover un item a otra cuenta que después se
> cancela es indistinguible de haberlo capturado allí desde el principio (D264). Dividir reparte el **importe** y no los
> items, con el centavo sobrante cargado a la primera parte (D262). Y ninguna operación toca una cuenta con pagos: es lo
> que garantiza que juntar dos cuentas no reescriba propinas ya cobradas (D263).

> **Paso 13:** las mesas en operación. Casi todo estaba ya —ocupar al abrir, «cuenta solicitada» al pedirla, liberar al
> pagar, al mover y al juntar— y lo que faltaba era el hueco que el propio controlador tenía anotado desde el paso 5:
> **liberar a mano no comprobaba si la mesa tenía cuentas vivas**. La respuesta la sabe `Pos` y la pregunta `Floor`, que
> no lo conoce, así que va por un contrato del kernel con la dependencia invertida (D266) — la tercera forma de cruzar
> una frontera, después del evento que anuncia y la excepción que informa: la **pregunta**. Y se añadió mover una cuenta
> de mesa (D267).

> **Paso 14:** para llevar. Contador diario por sucursal con su propia tabla y `FOR UPDATE` — no se puede reutilizar el
> asignador de folios porque aquél no reinicia nunca, y el reinicio es el requisito entero (D268). El número se asigna
> dentro de la transacción de la cuenta: un hueco en el mostrador es un número que se grita y nadie recoge. Entregar y
> cobrar son hechos independientes (D269).
>
> Y volví a cometer un error ya advertido: puse el candado de «exige transacción» en una prueba de integración, donde
> `RefreshDatabase` hace que nunca pueda fallar. El encabezado del candado gemelo lo decía desde la Iteración 3 (D270).
>
> **Con esto termina la Tanda B.** El POS vende, comanda, imprime, cobra, descuenta, divide y entrega.

> **Paso 15:** el descuento de inventario por venta, **el único camino asíncrono** (§6.2, D272). Asíncrono no por
> velocidad sino porque una receta mal capturada no puede impedir un cobro. Idempotente por (cuenta, item, componente),
> que es lo que permite que reparar sea re-despachar. El almacén es el del área que preparó (D273), y eso es lo que hace
> que un conteo por área pueda cuadrar.

> **Paso 16:** gastos desde caja y fuera de caja, con umbral. El asiento va **dentro de la transacción** y no por
> evento, desviándose de §7.2: un evento intra-módulo no compra nada y un gasto sin su asiento es dinero que el corte no
> conoce (D274). El gasto de caja lleva el método de efectivo en su asiento — pasarlo en `null` lo asentaba como si no
> tocara el cajón, y el arqueo salía 250 pesos alto sin que nada fallara (D276). Y `CashSessionProbe` es el **segundo**
> contrato de pregunta en el kernel en dos pasos (D277): deja de parecer un caso particular.

> **Paso 17:** clientes mínimos y crédito — el mecanismo con el que §6.3 mata la «cuenta que nunca se cierra». El cargo
> al saldo es **síncrono** dentro del cobro y su asiento va por evento (D279): si el cargo llegara tarde, una cuenta
> podría quedar pagada sin cargar y el negocio habría regalado la comida. El saldo es proyección con el patrón del
> kardex (D280). Fiar no mueve caja; abonar sí, y es la mitad que falta para que el corte cuadre (D281).

> **Paso 18:** depósitos y liquidación de propinas — las dos mitades que le faltaban al recorrido del efectivo. El
> disponible de propinas se calcula **del diario** y no de `pos_payments` como decía §6.6: la decisión del paso 10 de
> poner como actor a quien se le atribuye la propina evitó un ciclo `Finance → Pos` sin que hiciera falta nada más
> (D283). Y el depósito es la **única** operación que no exige caja abierta, porque se captura con el comprobante en la
> mano, días después (D285).

> **Paso 19:** el corte, calculado del diario. El esperado en efectivo resultó ser una **suma** y no la fórmula
> enumerada de §6.5 —`SUM(amount) WHERE affects_cash_drawer`— con la propiedad de que un tipo nuevo entra solo (D286). Y
> el encabezado de `PosSessionClosed` afirmaba, desde el paso 6, que «quien calcula el corte tiene todo lo que
> necesita»: era falso, porque lo declarado vive en `Pos` (D288). El precorte es ciego por permisos y no por un reporte
> recortado (D289).

> **Paso 20:** la cáscara del POS —caja, piso de cuentas y la cuenta— que no calcula dinero: lo pide, y reemplaza la
> cuenta con la que devuelve cada escritura en lugar de sumarla en el navegador (D291). Antes de abrir el navegador
> apareció que la purga del demo había perdido **trece tablas**: las que se llenan operando están vacías en un tenant
> recién sembrado, así que el candado que existía no podía verlas. El candado nuevo comprueba la cobertura por
> estructura — y su primera versión, que daba por buena la cascada del tenant, era **circular** y habría aceptado una
> lista vacía (D290).

> **Paso 20 (verificación en navegador):** abrir el navegador encontró lo que 1148 pruebas en verde no podían. Un turno
> de caja se abría en **otra sucursal** (201 donde debía haber 403), y el mismo hueco estaba en **once** endpoints —
> gastos, depósitos, propinas y abonos incluidos. El guardián sube al kernel y queda con candado (D292). Las mesas
> salían duplicadas, la caja enseñaba el turno de la sucursal ajena y las horas se pintaban en la del navegador pese a
> que `branch_timezone` viajaba desde la Iteración 1 sin que nadie lo consumiera (D293). El cambio a devolver no se
> mostraba, y un filtro inventado dejaba la cuenta en blanco (D294).
>
> **Pendiente abierto y NO resuelto:** el efectivo esperado del corte queda **corto por el importe del cambio** en todo
> cobro en efectivo con cambio. Ver la sección de pendientes.

**Alcance original de la hoja de ruta (§14):** órdenes / comandas / cuentas, sesiones de caja, pagos y
propinas, impresión (trabajos + agente).

**Alcance de esta propuesta:** el POS **completo y operable**, con todo lo que necesita de otras iteraciones
para que un negocio real pueda abrir caja, atender mesas, cobrar de todas las formas previstas y cuadrar el
dinero al cerrar. Es una ampliación deliberada (D235) y cambia la hoja de ruta.

**Cinco decisiones ya tomadas** que este diseño da por firmes:

| # | Decisión | Consecuencia |
|---|---|---|
| D231 | Los eventos que cruzan módulos viven en el **shared kernel**, con primitivos | §7 |
| D232 | El **diario financiero** y los **métodos de pago** se adelantan a esta iteración | §6 |
| D233 | La propina se atribuye al **titular de la cuenta**, congelada en la línea de pago | §4.3 |
| D234 | El **rol activo se recuerda** y se reinicia al iniciar sesión | §10.1. Paso 0 |
| D235 | El POS entra **completo**: con mesas operativas, crédito a clientes y el arqueo cerrado | §1.5, §5, §6, §8 |

---

## 1. Las cinco preguntas que definen la iteración

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

Se congelan en la línea: **precio unitario, tasa de IVA** (paso 2 de D150), **nombre del artículo**, y los
**modificadores con su precio**. El nombre también, porque un ticket reimpreso un mes después tiene que decir
lo que decía el original aunque el artículo se haya renombrado o dado de baja.

### 1.3 ¿Puede el inventario o el costo detener un cobro?

**No.** §6.2 es explícito: el POS nunca se bloquea por inventario, el descuento es asíncrono y las existencias
negativas están permitidas. Este diseño lo lleva más lejos: **nada de lo que ocurre fuera del POS puede
impedir un cobro**. Si el diario falla al asentar, si la impresora está apagada, si la receta de un platillo
tiene un ciclo — la cuenta se cobra igual y el fallo queda registrado para repararse.

La lección es de la iteración 3: un oyente que lanzaba hizo que una confirmación de compra **mintiera**
diciendo que no había ocurrido (D220). En el POS eso sería peor: alguien pagó, tiene su cambio en la mano, y
la pantalla dice que no.

### 1.4 ¿De qué depende que el dinero cuadre?

De la **sesión de caja** y del **diario**. Sin sesión abierta no hay cobro (§6.3), y todo lo que mueve
efectivo pertenece a una sesión. El corte se **calcula** del diario y nunca se almacena como verdad paralela
(§6.5, ADR-004): la diferencia entre lo esperado y lo declarado es ella misma un movimiento tipado.

### 1.5 ¿Qué necesita el POS para estar completo? (D235)

La pregunta que abrió esta versión del diseño. La respuesta salió de recorrer §6.3 renglón por renglón y
preguntar «¿esto se puede operar sin aquello?». Cuatro cosas resultaron **necesarias**, no deseables:

| Lo que hace falta | Por qué el POS no funciona sin ello | De dónde venía |
|---|---|---|
| **Mesas** | El arquetipo primario es «restaurante con mesas, meseros y cuentas abiertas» (§1). Un POS que no sabe en qué mesa está una cuenta no sirve para el negocio para el que se diseñó, y §6.3 exige liberar la mesa al pagarse todas las sub-cuentas | Iteración 6 |
| **Gastos desde caja** | El cajero paga los garrafones con dinero de la caja. Un arqueo que no los conoce **no cuadra nunca**, y la diferencia deja de significar nada | Iteración 5 |
| **Crédito a clientes** | §6.3 prohíbe la «cuenta que nunca se cierra». El crédito **es** el mecanismo para el fiado: sin él, un negocio que da crédito deja cuentas abiertas para siempre, que es justo lo prohibido | Iteraciones 5 y 7 |
| **Liquidación de propinas** | Esta iteración crea las propinas. Sin liquidarlas, el dinero se acumula en la caja sin salida registrada y el arqueo se descuadra al entregarlas a mano | Iteración 5 |

Y una que yo había omitido y no venía de ninguna iteración: **las impresoras**. Las áreas de preparación y las
terminales no tienen a dónde imprimir (§9.1). Sin eso, «ruteo por área» no tiene destino y el cajón de dinero
—que se abre por la impresora— no se puede abrir.

**Lo que se quedó fuera y por qué**, en §11. La distinción que usé: **lo que impide operar entra; lo que mejora
la operación, no.**

---

## 2. Módulo `Pos` — sesión de caja

### 2.1 `pos_sessions`

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

### 2.3 `pos_session_declarations`

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `pos_session_id` | FK `pos_sessions` CASCADE | |
| `moment` | ENUM `precount`, `close` | |
| `payment_method_id` | FK `payment_methods` RESTRICT | |
| `declared_amount` | DECIMAL(12,2) | Lo que la persona dice que hay |
| `declared_by_membership_id` | FK | Puede no ser quien abrió |
| `declared_at` | TIMESTAMP | |

**Unique** `(pos_session_id, moment, payment_method_id)`.

**No lleva el esperado ni la diferencia.** Los dos se **calculan** del diario al vuelo (§6.3). Guardarlos sería
la verdad paralela que ADR-004 prohíbe, y quedarían desactualizados en cuanto se asentara un movimiento más.

### 2.4 `pos_session_withdrawals` — retiros parciales

`id`, `ulid`, `tenant_id`, `pos_session_id`, `amount`, `reason` VARCHAR(300),
`performed_by_membership_id`, `authorized_by_membership_id` nullable, `created_at`. **Append-only.**

Exige `pos.sessions.withdraw` y PIN. Asienta un movimiento `withdrawal` que afecta cajón. El **depósito** que
lo cierra está en §6.5.

---

## 3. Módulo `Pos` — cuentas, órdenes e items

### 3.1 `pos_accounts`

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid` | | |
| `tenant_id` | FK CASCADE | |
| `branch_id` | FK RESTRICT | NOT NULL |
| `series` / `folio` | | Foliación por (tenant, sucursal, `pos_account`, serie) |
| `kind` | ENUM `dine_in`, `takeout` | Sin `delivery` en v1; llega con e-commerce (9) |
| `status` | ENUM | `open`, `bill_requested`, `closed`, `paid`, `cancelled` |
| `table_id` | FK `tables` RESTRICT nullable | La mesa (§5). `null` en barra y para llevar |
| `label` | VARCHAR(60) nullable | Nombre libre: «Barra 3», «Señor de lentes» |
| `customer_id` | FK `customers` RESTRICT nullable | El cliente, si se identificó (§8) |
| `takeout_number` | UNSIGNED SMALLINT nullable | **El número que se grita** (§3.5) |
| `delivery_status` | ENUM `pending`, `ready`, `delivered` nullable | Sólo para llevar (§6.3) |
| `waiter_membership_id` | FK `tenant_memberships` RESTRICT | El **titular** (D233). NOT NULL |
| `pos_session_id` | FK `pos_sessions` RESTRICT | nullable mientras no se cobra; NOT NULL al pagar |
| `opened_by_membership_id` | FK | Quién la abrió, que puede no ser el titular |
| `opened_at` | TIMESTAMP | |
| `bill_requested_at` / `closed_at` / `paid_at` / `cancelled_at` | nullable | |
| `cancelled_reason` | VARCHAR(300) nullable | |
| `subtotal`, `discount_total`, `vat_total`, `total` | DECIMAL(12,2) | Proyección |
| `paid_total`, `tip_total`, `change_total` | DECIMAL(12,2) | Proyección |
| `parent_account_id` | FK propio RESTRICT nullable | De qué cuenta salió al dividir |
| `version` | UNSIGNED INT default 0 | **Candado optimista** |
| `timestamps` | | |

**La versión existe para que dos terminales no cobren la misma cuenta.** §11 de la Arquitectura lo pide por
nombre: «versión de cuenta verificada al pagar». Se incrementa en cada escritura y quien cobra manda la versión
que leyó; si no coincide, recibe **409** y vuelve a leer. Un lock pesimista serviría igual contra el cobro
doble, pero bloquearía al mesero que captura mientras el cajero mira la pantalla — y en un POS táctil eso se
nota.

**Los totales son proyección, no verdad.** Se recalculan de los items y de los pagos dentro de la misma
transacción que los modifica, igual que `article_stocks` se recalcula del kardex. La verdad son los items y las
líneas de pago; el total existe para no sumar veinte filas en cada listado del piso.

**Constraints:** `CHECK (total >= 0)`, `CHECK (paid_total >= 0)`, `CHECK (tip_total >= 0)`. Y **no** hay CHECK
que ate `status = 'paid'` a `paid_total >= total`: una cortesía es una venta en $0 legítima (§6.3) y un
descuento del 100 % también.

**Índices:** `(tenant_id, branch_id, status, opened_at)` — el piso; `(tenant_id, pos_session_id)` — el corte;
`(tenant_id, waiter_membership_id, paid_at)` — las propinas de un mesero; `(tenant_id, table_id, status)` — «¿qué
mesas están ocupadas?».

### 3.2 `pos_orders` y `pos_order_items`

`pos_orders`: `id`, `ulid`, `tenant_id`, `pos_account_id` (FK RESTRICT), `sequence` (el número de la orden
dentro de la cuenta), `created_by_membership_id`, `sent_at` nullable, `timestamps`. **Unique**
`(pos_account_id, sequence)`.

`pos_order_items` — **la línea que se cobra**:

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `pos_order_id` | FK CASCADE | |
| `pos_account_id` | FK RESTRICT | **Denormalizado a propósito**: mover un item entre cuentas cambia esta columna, y la orden se queda donde estaba porque describe lo que se preparó |
| `article_id` | FK `articles` RESTRICT | |
| `preparation_area_id` | FK RESTRICT nullable | El área que le tocó al comandar |
| `status` | ENUM `captured`, `commanded`, `preparing`, `served`, `cancelled` | §6.3 |
| `quantity` | DECIMAL(12,4) | Se puede vender media pizza |
| `article_name` | VARCHAR(120) | **Congelado** |
| `unit_price` | DECIMAL(12,2) | **Congelado**, IVA incluido (D30) |
| `vat_rate` | DECIMAL(5,2) | **Congelado** (paso 2 de D150) |
| `modifiers_total` | DECIMAL(12,2) | Suma de los extras, congelada |
| `discount_amount` | DECIMAL(12,2) default 0 | |
| `line_total` | DECIMAL(12,2) | **Generada STORED**: `(quantity * (unit_price + modifiers_total)) - discount_amount` |
| `is_courtesy` | BOOLEAN default false | Cortesía: sí descuenta inventario (§6.3) |
| `cancelled_reason` / `cancelled_by_membership_id` / `cancelled_at` | nullable | |
| `cancellation_destination` | ENUM `none`, `waste`, `restock` nullable | Qué se hizo con la comida |
| `captured_by_membership_id` | FK | |
| `timestamps` | | |

**Por qué `line_total` es columna generada:** el mismo argumento de `variance` y `balance_after` en la
iteración 3 — un total calculado en PHP y guardado se desincroniza en cuanto alguien escribe por otra vía. Y es
la multiplicación de dinero donde se cuela el error de redondeo (D134), así que la hace la base una sola vez.

**Constraints:** `CHECK (quantity > 0)`, `CHECK (unit_price >= 0)`, `CHECK (discount_amount >= 0)`,
`CHECK (vat_rate >= 0 AND vat_rate <= 100)`.

**Índices:** `(tenant_id, pos_account_id, status)` — la cuenta en pantalla; `(tenant_id,
preparation_area_id, status)` — «¿qué falta en esta barra?».

### 3.3 `pos_order_item_modifiers`

`id`, `ulid`, `tenant_id`, `pos_order_item_id` (CASCADE), `modifier_id` (FK RESTRICT), `modifier_name`
VARCHAR(80) **congelado**, `quantity` UNSIGNED SMALLINT (los 3 shots de D7), `extra_price` DECIMAL(12,2)
**congelado**, `timestamps`. **Unique** `(pos_order_item_id, modifier_id)`.

### 3.4 `pos_tickets` y `pos_ticket_items`

Una **comanda** es un fragmento de orden ruteado a un área. Un **ticket de cierre** y un **ticket final** son
documentos del mismo género: algo que se imprimió y de lo que hay que poder decir cuándo y quién.

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `kind` | ENUM `command`, `command_cancellation`, `bill_preview`, `final_receipt` | |
| `pos_account_id` | FK RESTRICT | |
| `pos_order_id` | FK RESTRICT nullable | Sólo las comandas |
| `preparation_area_id` | FK RESTRICT nullable | Sólo las comandas |
| `series` / `folio` | nullable | Sólo `final_receipt` folia (§3.6) |
| `issued_by_membership_id`, `issued_at` | | |
| `reprint_count` | UNSIGNED SMALLINT default 0 | Reimprimir es auditado |
| `timestamps` | | |

`pos_ticket_items`: `pos_ticket_id`, `pos_order_item_id`, `quantity` — porque se puede comandar parte de una
línea.

### 3.5 Para llevar, con el número que se grita

§6.3 pide «numeración visible» para el mostrador, y un folio de cuenta de cinco cifras no sirve para gritarlo.

`takeout_number` es un contador **por sucursal y por día de operación**, que vuelve a 1 cada jornada.
`pos_takeout_counters` (`tenant_id`, `branch_id`, `business_date`, `last_number`) con unique
`(tenant_id, branch_id, business_date)` y el mismo `FOR UPDATE` del asignador de folios.

**Por qué una tabla y no `MAX(takeout_number) + 1`:** dos pedidos simultáneos leerían el mismo máximo y
gritarían el mismo número. Es el problema que `DocumentNumberAllocator` ya resuelve, pero **no se puede
reutilizar tal cual**: aquél no reinicia nunca, y aquí el reinicio diario es el requisito.

`pos.takeout_payment_timing` (ya en el catálogo) decide si se cobra al ordenar o al recoger. Y
`delivery_status` recorre `pending → ready → delivered`, con `pos.takeout.manage`.

### 3.6 Qué folia y qué no

| Documento | Tipo de folio | Por qué |
|---|---|---|
| `pos_sessions` | `pos_session` | El corte se identifica por su número |
| `pos_accounts` | `pos_account` | Es el número que el cliente ve |
| `pos_tickets` tipo `final_receipt` | `pos_receipt` | Será el folio facturable (ADR-005) |

**No folían** las órdenes, las comandas ni los tickets de cierre: la orden se identifica por su `sequence`
dentro de la cuenta, y una comanda es un papel de cocina, no un documento con valor.

Y un límite que conviene decir ahora: la foliación **serializa** por (sucursal, tipo, serie). A 1 000
cuentas/día son ~4/minuto en hora pico y el lock dura milisegundos. Si alguna vez cada **item** foliara, el POS
se detendría. Es la razón de la tabla de arriba.

---

## 4. Cobro, pagos y propinas

### 4.1 `pos_payments` — inmutable

Es **append-only** (§7 lista `pagos` entre los inmutables). Corregir un pago es registrar su reversa.

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
| `tip_membership_id` | FK RESTRICT nullable | **A quién se le atribuye** (D233), congelado aquí |
| `reference` | VARCHAR(60) nullable | Autorización de tarjeta, folio de transferencia |
| `charged_by_membership_id` | FK | Quién cobró — puede no ser el titular |
| `reverses_payment_id` | FK propio RESTRICT nullable | La reversa enlaza a su original |
| `occurred_at` | TIMESTAMP | |
| `created_at` | | Sin `updated_at` |

**Constraints:** `CHECK (amount <> 0)` —una reversa es negativa—, `CHECK (tip_amount >= 0)`,
`CHECK (change_amount >= 0)`.

**Índices:** `(tenant_id, pos_session_id, payment_method_id)` — el corte agrupa exactamente por eso;
`(tenant_id, pos_account_id)`; `(tenant_id, tip_membership_id, occurred_at)` — la liquidación de propinas.

### 4.2 `payment_methods` (módulo `Finance`)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id` | | |
| `code` | CHAR(30) ascii_bin | único por tenant |
| `name` | VARCHAR(60) | |
| `kind` | ENUM `cash`, `card`, `transfer`, `customer_credit`, `custom` | |
| `affects_cash_drawer` | BOOLEAN | §6.3. El efectivo sí; la tarjeta no; el crédito **no** |
| `is_system` | BOOLEAN | Los del sistema no se borran ni se renombran |
| `requires_reference` | BOOLEAN | Una transferencia sin referencia no se concilia |
| `allows_change` | BOOLEAN | Sólo el efectivo da cambio |
| `status` | ENUM `active`, `inactive` | Se da de baja, no se borra: los pagos lo citan |
| `sort_order` | UNSIGNED SMALLINT | El orden de los botones en la caja |

Se siembran cuatro al provisionar: **efectivo** (afecta cajón, da cambio), **tarjeta**, **transferencia** (con
referencia) y **crédito de cliente** (no afecta cajón, §8.3).

### 4.3 La propina, con nombre y apellido

D233: la cuenta tiene un **titular** y cada línea de pago **congela** en `tip_membership_id` a quién se le
atribuye su propina, en el momento del cobro.

Lo que eso compra: **una operación posterior no reescribe propinas ya pagadas**. Si a las 22:00 se juntan dos
cuentas, las propinas cobradas a las 21:00 siguen siendo de quien las ganó. Sin congelarlas, juntar cuentas
movería dinero de una persona a otra sin que nadie lo hubiera decidido.

Al **dividir**, cada subcuenta hereda el titular. Al **juntar**, manda el titular de la cuenta destino y el
cambio queda escrito en el historial de la operación (§4.5).

### 4.4 Descuentos y cortesías — la zona de máxima auditoría

`pos_discounts`, **append-only**: `id`, `ulid`, `tenant_id`, `pos_account_id`, `pos_order_item_id` nullable
(null = descuento de cuenta), `kind` ENUM `percentage`/`amount`/`courtesy`, `value` DECIMAL(12,2),
`resulting_amount` DECIMAL(12,2), `reason` VARCHAR(300) **NOT NULL**, `applied_by_membership_id`,
`authorized_by_membership_id` nullable, `created_at`.

Tres cosas que no se negocian, porque §6.3 llama a esto «zona de máxima auditoría»:

1. **El motivo es obligatorio.** Mismo argumento que las mermas (D27): un descuento sin motivo es dinero que
   nadie puede explicar.
2. **Exige PIN** (ADR-008). El actor real queda registrado, distinto de quien tiene la terminal abierta.
3. **`resulting_amount` se calcula en el servidor.** Si el cliente calculara el monto, un 10 % podría llegar
   como cualquier cifra.

Una **cortesía** es una venta en $0 que **sí descuenta inventario** (§6.3): se modela como descuento de tipo
`courtesy` y la línea queda con `is_courtesy = true`.

### 4.5 Operaciones de cuenta, historizadas

`pos_account_operations`, **append-only**: `id`, `ulid`, `tenant_id`, `kind` ENUM
`split`/`merge`/`move_items`/`reopen`, `source_account_id`, `target_account_id` nullable,
`performed_by_membership_id`, `authorized_by_membership_id` nullable, `detail_count`, `created_at`.

Y `pos_account_operation_items`: `operation_id`, `pos_order_item_id`, `from_account_id`, `to_account_id`.

Sin esto, mover un item entre cuentas es indistinguible de haberlo capturado allí desde el principio — y ése es
el hueco por el que se va la mercancía en un bar.

**Dividir por partes iguales** (§6.3) no reparte items: crea N subcuentas y reparte el **importe**, dejando los
items en la cuenta madre. Es la única forma honesta de dividir «entre cuatro» una botella que nadie pidió
individualmente.

### 4.6 Las máquinas de estados

**Cuenta:**

```
open ──► bill_requested ──► closed ──► paid
  │            │
  └────────────┴──────────► cancelled
```

- `open → bill_requested`: imprime el **ticket de cierre**. Con `pos.lock_items_on_bill_request` activo, no se
  capturan más items.
- `bill_requested → closed`: el total queda fijado. Se vuelve a `open` con `pos.accounts.reopen`, auditado.
- `closed → paid`: cuando `paid_total >= total`. Emite el **ticket final** con desglose de pagos y propina.
- `→ cancelled`: **sólo sin pagos aplicados**. Una cuenta con pagos se corrige por reversa.

**Item:**

```
captured ──► commanded ──► preparing ──► served
    │             │             │
    └── borrado    └─────────────┴──────► cancelled
```

- **Cancelar no comandado = borrar** (§6.3): no hay rastro porque no ocurrió nada.
- **Cancelar comandado** exige **motivo + PIN**, emite una **comanda de cancelación** al área, y pide el
  **destino**: `waste` si ya se preparó (genera merma con su motivo) o `restock` si no se tocó.

Las transiciones permitidas las expone el servidor en `allowed_next`, como en las transferencias: el cliente no
lleva su propia copia de la máquina.

---

## 5. Mesas — `Floor` operativo (D235)

**Entra el modelo y la operación; no entra el editor visual.** La distinción es la que separa «el POS sabe en
qué mesa está una cuenta» de «alguien dibuja el salón con el ratón». Lo primero es necesario para operar; lo
segundo es la superficie de la iteración 6, que además exige ADR-003 (SVG + Vue puro) y tiempo real.

Las mesas se dan de alta **por formulario** (nombre, zona, capacidad) con coordenadas por omisión. La iteración
6 las coloca visualmente sobre el plano.

### 5.1 `floor_plans` y `floor_zones`

`floor_plans`: `id`, `ulid`, `tenant_id`, `branch_id` (RESTRICT), `name` VARCHAR(60), `is_default` BOOLEAN,
`status`, `timestamps`. **Múltiples planos por sucursal** (D34) desde el modelo, aunque en esta iteración se
opere con uno.

`floor_zones`: `id`, `ulid`, `tenant_id`, `floor_plan_id` (CASCADE), `name` VARCHAR(60), `sort_order`,
`timestamps`. Terraza, salón, barra.

### 5.2 `tables`

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `floor_zone_id` | FK RESTRICT | |
| `code` | CHAR(10) ascii_bin | «M1», «T4». Único por sucursal |
| `name` | VARCHAR(60) nullable | |
| `seats` | UNSIGNED SMALLINT | Capacidad |
| `status` | ENUM `free`, `occupied`, `bill_requested`, `needs_cleaning`, `reserved` | §6.4. `reserved` **previsto y no usado** (D33) |
| `x`, `y`, `width`, `height` | DECIMAL(8,2) | **Coordenadas lógicas, nunca píxeles** (ADR-003) |
| `rotation` | DECIMAL(5,2) default 0 | |
| `shape` | ENUM `rectangle`, `circle` | |
| `joined_to_table_id` | FK propio RESTRICT nullable | La unión temporal (§5.3) |
| `timestamps` | | |

**Unique** `(tenant_id, branch_id, code)`. **Índice** `(tenant_id, branch_id, status)`.

**`needs_cleaning` es configurable** (§6.4): con `floor.use_cleaning_state` apagado, una mesa pagada vuelve
directo a `free`.

### 5.3 Unión temporal de mesas (D32)

`joined_to_table_id` apunta a la **mesa principal** de la unión. Las mesas unidas comparten la cuenta de la
principal, y la unión **se deshace automáticamente al pagarse** — que es lo que §6.4 llama «operativa y
temporal».

No hay tabla de uniones y eso es deliberado: una unión es un estado del momento, no un documento. Lo que sí
queda registrado es la cuenta que la usó, que es lo que alguien querría auditar.

### 5.4 Liberar la mesa

§6.3: «la mesa se libera cuando **todas** las sub-cuentas están pagadas». Se resuelve en el oyente de
`PosAccountPaid`: si no queda ninguna cuenta abierta en la mesa —ni la madre ni sus divisiones—, la mesa pasa a
`needs_cleaning` o a `free` según la configuración, y la unión se deshace.

**Y no puede ser un `CHECK` ni un trigger**: depende de N filas de otra tabla. Es lógica de aplicación con su
prueba, y la prueba que importa es la de dividir en cuatro y pagar tres.

---

## 6. Módulo `Finance` (D232, D235)

### 6.1 `financial_movements` — inmutable, tipado, con origen (ADR-004)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `type` | ENUM | `sale`, `payment`, `change`, `tip`, `tip_settlement`, `discount`, `courtesy`, `expense`, `withdrawal`, `deposit`, `credit_granted`, `credit_repayment`, `opening_float`, `count_difference`, `reversal` |
| `pos_session_id` | FK RESTRICT nullable | NOT NULL para todo lo que toca caja |
| `payment_method_id` | FK RESTRICT nullable | |
| `affects_cash_drawer` | BOOLEAN | **Copiado** del método al asentar: si mañana alguien cambia la bandera, los cortes de ayer no deben cambiar |
| `amount` | DECIMAL(12,2) | Con signo |
| `source_type` / `source_ulid` | VARCHAR(120) / CHAR(26) | El documento origen por ULID, no por llave interna (D151) |
| `actor_membership_id` | FK RESTRICT | |
| `reverses_movement_id` | FK propio RESTRICT nullable | |
| `occurred_at` | TIMESTAMP | |
| `created_at` | | Sin `updated_at` |

**Índices:** `(tenant_id, pos_session_id, type)` — el corte; `(tenant_id, branch_id, occurred_at)` — los
reportes de la 8; `(tenant_id, source_type, source_ulid)` — «¿qué asentó este documento?».

**Idempotencia:** unique `(tenant_id, source_type, source_ulid, type)`. Re-despachar el evento de un pago no
duplica su asiento — la regla de jobs idempotentes aplicada al dinero.

### 6.2 Quién escribe en el diario

**Nadie directamente.** El POS emite eventos del kernel (§7) y `Finance` los asienta con un oyente. La regla 3
de §2 no admite matices aquí, y es la que hace que el diario sea auditable: hay **un** camino de escritura.

### 6.3 El corte, calculado

No es una tabla. Es una **consulta** sobre `financial_movements` agrupada por método:

```
esperado(efectivo) = opening_float + Σ pagos − Σ cambios − Σ retiros − Σ gastos desde caja
                     − Σ liquidaciones de propina + Σ abonos de crédito
esperado(otros)    = Σ pagos del método
declarado          = pos_session_declarations donde moment = 'close'
diferencia         = declarado − esperado
```

Y la diferencia, si no es cero, **se asienta** como movimiento `count_difference` (§6.5: «Diferencia =
movimiento tipado»). Así el diario cuadra consigo mismo y la diferencia queda con nombre, monto y actor.

**Nótese lo que la fórmula demuestra:** sin gastos desde caja, sin liquidación de propinas y sin abonos de
crédito, el «esperado» sería sistemáticamente falso. Es el argumento de §1.5 en una línea.

### 6.4 Gastos — `expense_categories` y `expenses`

`expense_categories`: `id`, `ulid`, `tenant_id`, `name` VARCHAR(60), `is_system` BOOLEAN, `status`,
`timestamps`. **Mismo catálogo** para gastos desde caja y fuera de caja (§6.5).

`expenses`, **append-only**:

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `expense_category_id` | FK RESTRICT | |
| `source` | ENUM `cash_session`, `outside_cash` | Lo que decide si afecta el arqueo |
| `pos_session_id` | FK RESTRICT nullable | NOT NULL si `source = cash_session` |
| `payment_method_id` | FK RESTRICT nullable | Para los de fuera de caja: transferencia, tarjeta empresa |
| `amount` | DECIMAL(12,2) | |
| `description` | VARCHAR(300) | NOT NULL |
| `receipt_path` | VARCHAR(300) nullable | Comprobante **opcional** (§6.5) |
| `created_by_membership_id` | FK | |
| `authorized_by_membership_id` | FK nullable | Sobre el umbral (§6.5) |
| `occurred_at`, `created_at` | | |

**Umbral con PIN**, con el patrón que ya existe: ajuste `finance.expense_authorization_threshold`, y sobre él
un **409 `authorization_required`** con `finance.expenses.authorize_above_threshold`. Es el tercer uso del
mismo contrato (mermas, conteos, gastos), lo que confirma que ADR-008 estaba bien planteada.

### 6.5 Depósitos — `bank_deposits`

`id`, `ulid`, `tenant_id`, `branch_id`, `amount`, `bank_name` VARCHAR(60), `reference` VARCHAR(60),
`deposited_on` DATE, `created_by_membership_id`, `created_at`. **Append-only.**

Es la **referencia bancaria simple** de D38: banco, fecha, folio. Sin conciliación, que es evolución.

Y cierra el retiro: el dinero sale de la caja con un `withdrawal` y entra al banco con un `deposit`. Sin esto,
un retiro deja el efectivo en un limbo declarado.

### 6.6 Liquidación de propinas — `tip_settlements`

`id`, `ulid`, `tenant_id`, `branch_id`, `pos_session_id` (RESTRICT), `membership_id` (a quién se le paga),
`amount`, `paid_by_membership_id`, `created_at`. **Append-only.**

**Liquidación simple** (D39): un movimiento tipado que **afecta cajón**, porque la propina se entrega en
efectivo de la caja. El monto disponible se **calcula** de `pos_payments` agrupado por `tip_membership_id`
menos lo ya liquidado — otra cifra que no se almacena.

---

## 7. Eventos y fronteras (D231)

### 7.1 Los contratos viven en el kernel

Nuevo espacio: `app/Modules/Shared/Domain/Events/`. Un evento que cruza módulos lleva **sólo primitivos**:
ULIDs, montos como cadena, enteros. Nunca un modelo Eloquent.

Dos razones, y la segunda importa más que la arquitectónica:

1. Nadie declara depender de un módulo operativo, así que la regla 2 de §2 se respeta tal como está escrita.
2. **Los eventos se serializan a la cola.** Pasar un modelo a un job y recargarlo al otro lado es una fuente
   conocida de bugs: el modelo pudo cambiar entre el despacho y el consumo.

### 7.2 Los eventos de esta iteración

| Evento | Lo emite | Lo escucha | Efecto |
|---|---|---|---|
| `PosOrderCommanded` | `Pos` | `Printing` | Un trabajo de impresión por área |
| `PosItemsCancelled` | `Pos` | `Printing`, `Inventory` | Comanda de cancelación; merma si el destino es `waste` |
| `PosAccountPaid` | `Pos` | `Finance`, `Inventory`, `Printing`, `Floor` | Asienta pagos/propina/cambio; **descuenta insumos en cola**; ticket final; libera la mesa |
| `PosSessionOpened` / `PosSessionClosed` | `Pos` | `Finance` | Fondo y diferencia de corte |
| `PosWithdrawalRegistered` | `Pos` | `Finance` | El retiro |
| `PosDiscountApplied` | `Pos` | `Finance` | El descuento o la cortesía |
| `ExpenseRegistered` | `Finance` | `Finance` | Interno: el asiento del gasto |
| `CustomerCreditGranted` / `CustomerCreditRepaid` | `Pos` / `Customers` | `Finance`, `Customers` | El saldo y su asiento |

**El único asíncrono es el descuento de inventario** (§6.2). Los demás corren después del commit en la misma
petición, porque quien cobra necesita ver su ticket. Y **ningún oyente puede tumbar el cobro**: el fallo se
registra y no se propaga, con la lección de D220 aplicada desde el diseño y no después.

### 7.3 Los eventos que ya existen — **corregido al implementar (D236)**

Este apartado decía que se migraban **dos** eventos. El mapa real de oyentes registrados dijo otra cosa:

- `ArticleCostChanged` **no cruza módulos**: lo emite `Costing` y lo escucha `Costing`. Su comentario afirmaba que
  inventarios lo escuchaba, y era falso — yo lo di por bueno al escribir esto. Corregido en el archivo.
- `StockMovementRecorded`, `RecipeChanged` y `ArticlePriceChanged` no tienen oyentes fuera de su módulo.
- `TenantProvisioned` lo escucha `Catalog`, pero su emisor es **kernel** y depender del kernel está permitido.

Así que sólo cruza uno: `PurchaseReceiptConfirmed`. Y **no se migra**, con su razón: su oyente de `Inventory` escribe el
enlace de vuelta dentro de la misma transacción que crea el movimiento, y ése es el mecanismo que hace detectable una
confirmación a medias (D220). Invertirlo exigiría escuchar a `StockMovementRecorded`, que se emite **fuera** de la
transacción a propósito. Queda como **excepción declarada** en el candado, con su plan de migración escrito.

El paso 1 entrega entonces el espacio de nombres, la interfaz `CrossModuleEvent`, la convención en §2 de la Arquitectura
y el candado con sus tres comprobaciones — que es lo que hace falta para que los **seis eventos nuevos del POS** nazcan
bien. `Inventory` y `Costing` conservan su `depends_on: ['Purchasing']` mientras la excepción exista.

### 7.4 El descuento de inventario por venta

El único camino asíncrono, y el más delicado del sistema. Al pagarse una cuenta, un job por cuenta:

1. Por cada item **no cancelado** (incluidas las cortesías), resuelve qué consumir:
   - **inventariable y no producible** → se consume él mismo.
   - **producible** → se explota su receta con `ResolveProductionConsumption`, que ya aplica rendimiento y
     conversión de unidades.
   - **modificadores con receta** → se explotan igual (`recipes` ya tiene `modifier_id`).
2. El almacén es el del **área de preparación** que atendió el item (`preparation_areas.warehouse_id`). Un item
   sin área —una cerveza que el mesero saca de la nevera— usa el almacén de la sucursal.
3. Registra los movimientos `sale_consumption` por la puerta única del kardex, con llave de idempotencia
   `pos_account:{ulid}:item:{ulid}:{component_ulid}`.

**Existencias negativas permitidas** y el job **nunca reintenta hacia el cobro**: si falla, la cuenta sigue
pagada y el job queda para reparar. Re-despacharlo no duplica nada.

---

## 8. Clientes y crédito — `Customers` mínimo (D235)

**Entra lo que el cobro necesita; no entra el expediente del cliente.**

### 8.1 `customers`

`id`, `ulid`, `tenant_id`, `code` CHAR(20) nullable, `name` VARCHAR(120), `phone` VARCHAR(20) nullable,
`email` VARCHAR(120) nullable, `notes` VARCHAR(300) nullable, `status`, `created_by_membership_id`,
`timestamps`.

**Unique** `(tenant_id, phone)` cuando el teléfono existe: es el identificador con el que se busca a alguien en
el mostrador, y dos clientes con el mismo teléfono son un error de captura.

**Alta express desde el POS** (D43): nombre + teléfono y nada más. Todo lo demás es nullable a propósito —
pedirle la razón social a alguien que está pagando un café es exactamente lo que hace que nadie registre
clientes.

### 8.2 `customer_credits` — el saldo

`id`, `ulid`, `tenant_id`, `customer_id` (unique), `credit_limit` DECIMAL(12,2), `balance` DECIMAL(12,2),
`is_enabled` BOOLEAN, `timestamps`.

**`balance` es proyección**, no verdad: se recalcula de `customer_credit_movements`. La misma decisión que
`article_stocks` frente al kardex, y por la misma razón — un saldo almacenado como verdad única se desvía y
nadie puede reconstruirlo.

### 8.3 `customer_credit_movements` — inmutable

`id`, `ulid`, `tenant_id`, `customer_id`, `type` ENUM `charge`/`repayment`/`adjustment`, `amount`,
`source_type`/`source_ulid`, `pos_session_id` nullable, `balance_after` DECIMAL(12,2) **generada o calculada
bajo lock**, `created_by_membership_id`, `created_at`.

El patrón es el del kardex, deliberadamente: una sola puerta de escritura, saldo por movimiento, y el balance
del cliente como proyección reconstruible.

**Cobrar a crédito** es un pago con método `customer_credit`: **no afecta cajón** (§6.3), carga el saldo del
cliente y **la cuenta queda pagada**. Eso es lo que mata la «cuenta que nunca se cierra»: el fiado deja de ser
una cuenta abierta y pasa a ser un saldo con nombre.

**El límite se verifica al cobrar**, y sobrepasarlo responde **409 `authorization_required`** con
`finance.customer_credit.manage`. No 422: no hay nada que corregir en el formulario — hace falta que alguien
autorice.

**Los abonos afectan cajón al ocurrir** (§6.3): entran a la caja como efectivo y se asientan como
`credit_repayment`.

---

## 9. Impresión

### 9.1 `printers` (módulo `Organization`) — lo que faltaba

Las áreas de preparación y las terminales **no tienen a dónde imprimir**. Sin esto, «ruteo por área» no tiene
destino y el cajón de dinero —que se abre por la impresora— no se puede abrir. No venía de ninguna iteración:
era un hueco.

`printers`: `id`, `ulid`, `tenant_id`, `branch_id` (RESTRICT), `code` CHAR(20), `name` VARCHAR(60),
`connection` ENUM `network`, `usb`, `windows_share`, `target` VARCHAR(120) (IP, cola o nombre de recurso),
`paper_width` UNSIGNED SMALLINT (58 u 80 mm), `supports_cash_drawer` BOOLEAN, `status`, `timestamps`.

**Unique** `(tenant_id, branch_id, code)`.

Y dos columnas nuevas nullable: `preparation_areas.printer_id` y `terminals.printer_id`.

**Por qué una tabla y no una cadena en el área:** con 2–5 impresoras por sucursal, la cadena se repetiría en
cada área y cambiar una IP obligaría a editarlas todas. Y `supports_cash_drawer` no cabe en una cadena.

### 9.2 `print_jobs` (módulo `Printing`)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `ulid`, `tenant_id`, `branch_id` | | |
| `kind` | ENUM `ticket`, `drawer_open` | El cajón se abre mandando un trabajo a la impresora |
| `pos_ticket_id` | FK RESTRICT nullable | `null` cuando es `drawer_open` |
| `printer_id` | FK `printers` RESTRICT | |
| `status` | ENUM `pending`, `claimed`, `printed`, `failed`, `cancelled` | |
| `payload` | JSON | **La excepción autorizada** (CLAUDE.md): impresión y bitácora |
| `attempts` | UNSIGNED SMALLINT default 0 | |
| `claimed_by_agent` | VARCHAR(80) nullable | |
| `claimed_at`, `printed_at`, `failed_at` | nullable | |
| `last_error` | VARCHAR(300) nullable | |
| `timestamps` | | |

**Índice** `(tenant_id, branch_id, status, id)` — la consulta del agente es «dame lo pendiente de esta
sucursal», y el `id` al final hace el orden determinista.

### 9.3 `print_agents` — quién puede reclamar trabajos

`id`, `ulid`, `tenant_id`, `branch_id`, `name` VARCHAR(60), `token_hash`, `last_seen_at` nullable, `status`,
`timestamps`.

**Un agente no es un usuario** y no debe autenticarse como uno: no tiene rol activo, no tiene permisos y no
opera nada — sólo reclama trabajos de su sucursal y reporta el resultado. Darle una membresía sería abrirle la
API entera a un proceso que vive en una computadora de la cocina.

### 9.4 El agente, y qué entra de él

El `.module.md` de `Printing` ya lo dice: el **puente Flutter** es v1 y el agente de escritorio Windows es la
segunda implementación. En esta iteración entra el **contrato**, que es lo que no se puede improvisar después:

- `GET /print-jobs/next` — reclama en lote (`pending → claimed`) con lock.
- `POST /print-jobs/{job}/printed` y `/failed` — idempotentes.
- `POST /print-jobs/{job}/reprint` — auditado, con `printing.jobs.reprint`.
- Autenticación por **token de agente** con su propio scope.

**No entra** el ejecutable: ni instalador, ni descubrimiento de impresoras, ni servicio de Windows. Sí entra un
**cliente de prueba** que consuma el contrato de punta a punta e imprima a archivo, porque sin él el contrato
no está verificado — y verificarlo es lo que hace que el agente real sea un trabajo de un día.

---

## 10. Permisos y configuración

### 10.1 El rol activo se recuerda (D234)

Paso 0, y va en el kernel: columna `tenant_memberships.last_active_role_id` (FK `roles` SET NULL), escrita por
`ResolveTenantContext` cuando el rol cambia, como ya se escribe `last_active_branch_id`. **Se reinicia al
iniciar sesión.**

### 10.2 Permisos

**Ya declarados en el catálogo cerrado** y consumidos por primera vez aquí: los 20 de `pos.*`, los 3 de
`printing.*`, los 10 de `finance.*` (incluido `finance.journal.view`, que ya existía), los 4 de `customers.*` y
los 3 de `floor.*`.

**Nuevos**, tres:

| Permiso | Por qué |
|---|---|
| `finance.payment_methods.manage` | El tenant configura sus métodos custom con su bandera de cajón |
| `organization.printers.view` / `.manage` | La impresora es infraestructura de la sucursal, como la terminal |

**Sin ruta a propósito:** `customers.fiscal_profiles.manage` y `customers.addresses.manage` (llegan con CFDI en
la 7), y `floor.layouts.edit` (el editor visual es de la 6). Se documentan como tales en la revisión, con el
método propuesto en la revisión de la iteración 3 — declarar **cómo** se consume cada permiso.

### 10.3 Qué exige PIN

`pos.discounts.*`, `pos.items.cancel_commanded`, `pos.cash_drawer.open`, `pos.accounts.reopen`,
`pos.sessions.withdraw`, `finance.expenses.authorize_above_threshold`, y sobrepasar el límite de crédito. Todas
responden **409 `authorization_required`**, y el diálogo del frontend ya existe desde la iteración 3.

### 10.4 Configuración nueva

| Llave | Tipo | Por omisión | Alcance | Por qué |
|---|---|---|---|---|
| `pos.tip_suggestions` | Enum | `10,15,20` | Sucursal | Los botones de propina sugerida |
| `pos.cancellation_default_destination` | Enum | `waste` | Sucursal | §6.3: destino configurable |
| `pos.account_label_required` | Bool | `false` | Sucursal | Un bar quiere nombre en cada cuenta; una fonda no |
| `floor.use_cleaning_state` | Bool | `true` | Sucursal | §6.4: «por limpiar» es configurable |
| `finance.expense_authorization_threshold` | Decimal | `1000.00` | Sucursal | §6.5: umbral de autorización |
| `finance.customer_credit_default_limit` | Decimal | `0.00` | Tenant | Cero = hay que habilitarlo cliente por cliente |
| `printing.job_max_attempts` | Int | `5` | Tenant | Cuándo dejar de reintentar |
| `printing.reprint_requires_pin` | Bool | `true` | Sucursal | Reimprimir un ticket final es material para un fraude |

Las tres que ya existen (`pos.blind_precount`, `pos.lock_items_on_bill_request`,
`pos.takeout_payment_timing`) se **consumen** por primera vez aquí.

---

## 11. Qué NO entra

La distinción que usé: **lo que impide operar entra; lo que mejora la operación, no.**

| Fuera | Por qué | Deuda que genera |
|---|---|---|
| **Editor SVG de planos y vista de piso en vivo** | Es superficie visual con su propia ADR (ADR-003) y su propia infraestructura. El POS opera sabiendo la mesa; dibujarla es otra cosa | Las mesas se dan de alta por formulario y sus coordenadas quedan por omisión hasta la iteración 6 |
| **Tiempo real (Reverb)** | El POS refresca por petición y funciona. WebSockets, autorización de canales y reconexión son una iteración | El piso no se actualiza solo: hay que recargar |
| **Promociones** (D50) | **No impiden operar.** Los descuentos manuales cubren el caso, y meter un motor de promociones antes de que el cálculo de la cuenta esté probado es el orden equivocado | Sin happy hour ni NxM hasta la 7 |
| **Perfiles fiscales, direcciones, CFDI** | ADR-005: el timbrado es la primera gran evolución. El ticket final ya folia y ése será el folio facturable | Sin factura hasta la 7 |
| **Historial rico del cliente, cumpleaños** | Es reporte, y los reportes son la 8 | |
| **Caja chica** (D37) y **conciliación bancaria** | Declaradas como evoluciones en la Especificación | |
| **Agente de impresión ejecutable** | Segunda implementación, con el puente Flutter en la 10 | Contrato verificado con cliente de prueba |
| **KDS** | Fuera de v1 explícitamente | Las comandas se imprimen |

**Y una consecuencia de plan que hay que decir en voz alta:** con este alcance, la iteración 5 (Finanzas) se
queda **casi sin contenido propio** —le restan la caja chica y la conciliación, que son evoluciones— y la 6 se
queda con el editor visual y el tiempo real. La hoja de ruta §14 hay que reescribirla, y la propuesta está en
§15.

---

## 12. Pruebas (Definition of Done)

Además de lo que CLAUDE.md exige siempre:

1. **Aislamiento de tenant** de `Pos`, `Finance`, `Printing`, `Floor` y `Customers` **por los caminos reales**,
   como en el paso 10 de la iteración 3: no basta consultar el modelo, hay que intentar llegar por cada FK.
2. **Concurrencia** (suite propia): dos terminales no abren dos sesiones en la misma caja; dos cobros
   simultáneos de la misma cuenta no la pagan dos veces; dos agentes no reclaman el mismo trabajo; dos pedidos
   para llevar simultáneos no gritan el mismo número.
3. **Idempotencia**: re-despachar `PosAccountPaid` no duplica asientos, movimientos de inventario ni cargos de
   crédito.
4. **Ningún oyente tumba el cobro**: prueba que hace fallar cada oyente y verifica que la cuenta queda pagada,
   el ticket existe y el fallo está en el log.
5. **Autorización**: matriz permiso × contexto para descuentos, cancelación comandada, cajón, reapertura,
   retiro, gasto sobre umbral y crédito sobre límite, con PIN de otra persona y actor real en la bitácora.
6. **El corte cuadra**: propiedad verificada sobre una sesión con pagos de tres métodos, cambios, propinas,
   descuentos, cortesías, un retiro, un gasto desde caja, una liquidación de propina y un abono de crédito.
   Esperado del diario = suma de sus partes.
7. **Los congelados no se mueven**: cambiar precio, nombre y tasa de IVA **después** de capturar, y verificar
   que la línea y el ticket no cambian.
8. **La mesa se libera cuando toca**: dividir en cuatro, pagar tres, comprobar que sigue ocupada; pagar la
   cuarta, comprobar que se libera y la unión se deshace.
9. **El saldo de crédito se reconstruye**: recalcular `balance` de los movimientos y comprobar que coincide,
   como ya se hace con las existencias.
10. **Verificación en navegador** obligatoria (§11): abrir caja, capturar, comandar, dividir, cobrar con dos
    métodos y propina, cobrar a crédito, registrar un gasto, liquidar una propina y cerrar con diferencia.

---

## 13. Orden de implementación — tres tandas, veinte pasos

Una iteración de este tamaño no se entrega de una vez sin verificar. Se parte en **tres tandas**, cada una con
su verificación en navegador y su commit, sin partir la iteración.

### Tanda A — los cimientos (nada de esto es POS todavía)

| # | Paso | Estado |
|---|---|---|
| 0 | Rol activo persistente (D234) | **Entregado** |
| 1 | Contratos de evento en el kernel (D231) | **Entregado** (D236: el evento existente no se migra) |
| 2 | `printers` + asignación a áreas y terminales | **Entregado** |
| 3 | `payment_methods` + siembra al provisionar | |
| 4 | `financial_movements` + `expense_categories` + el servicio que asienta | **Entregado**. El **oyente** llega con el primer evento del POS (paso 8): hoy no hay qué escuchar |
| 5 | `floor_plans`, `floor_zones`, `restaurant_tables` + alta por formulario | **Entregado** |

### Tanda B — vender y cobrar

| # | Paso |
|---|---|
| 6 | `pos_sessions` + apertura/cierre + retiros. **Incluye la FK `financial_movements.pos_session_id`** | **Entregado**. El arqueo (esperado vs declarado) llega en el paso 19 |
| 7 | `pos_accounts` + `pos_orders` + `pos_order_items` con los congelados |
| 8 | Comandar: `pos_tickets` + ruteo por área + máquina de estados del item |
| 9 | `print_jobs` + `print_agents` + contrato + cliente de prueba + cajón |
| 10 | Cobro: `pos_payments`, propina, cambio, ticket final |
| 11 | Descuentos y cortesías con PIN |
| 12 | Operaciones de cuenta: dividir, mover, juntar, reabrir |
| 13 | Mesas en operación: asignar, unir, liberar al pagar |
| 14 | Para llevar: numeración diaria y estados de entrega |

### Tanda C — que el dinero cuadre

| # | Paso |
|---|---|
| 15 | Descuento de inventario por venta, en cola (con worker) |
| 16 | Gastos desde caja y fuera de caja, con umbral |
| 17 | Clientes mínimos + crédito + cobro a crédito + abonos |
| 18 | Depósitos y liquidación de propinas |
| 19 | Corte y precorte ciego |
| 20 | UI del POS y de la caja, verificada en navegador |

---

## 14. Resumen: veintiocho tablas nuevas

| Módulo | Tablas | # |
|---|---|---|
| `Pos` | `pos_sessions`, `pos_session_declarations`, `pos_session_withdrawals`, `pos_accounts`, `pos_orders`, `pos_order_items`, `pos_order_item_modifiers`, `pos_tickets`, `pos_ticket_items`, `pos_payments`, `pos_discounts`, `pos_account_operations`, `pos_account_operation_items`, `pos_takeout_counters` | 14 |
| `Finance` | `payment_methods`, `financial_movements`, `expense_categories`, `expenses`, `bank_deposits`, `tip_settlements` | 6 |
| `Floor` | `floor_plans`, `floor_zones`, `restaurant_tables` | 3 |
| `Customers` | `customers`, `customer_credits`, `customer_credit_movements` | 3 |
| `Printing` | `print_jobs`, `print_agents` | 2 |
| `Organization` | `printers` | 1 |

**Nueve son append-only**: `pos_payments`, `pos_discounts`, `pos_account_operations`,
`pos_session_withdrawals`, `financial_movements`, `expenses`, `bank_deposits`, `tip_settlements`,
`customer_credit_movements`. Todas se declaran en la lista de inmutables de §7 de la Arquitectura, que tiene
candado en las dos direcciones.

Más dos columnas en tablas existentes (`preparation_areas.printer_id`, `terminals.printer_id`) y una en el
kernel (`tenant_memberships.last_active_role_id`).

---

## 15. La hoja de ruta, reescrita

Si se aprueba este alcance, §14 de la Arquitectura Maestra queda así:

| # | Antes | Después |
|---|---|---|
| 4 | POS núcleo | **POS completo**: órdenes/comandas/cuentas, caja y arqueo, pagos y propinas, mesas operativas, crédito a clientes, gastos, impresión |
| 5 | Finanzas | **Absorbida en la 4.** Lo que restaba —caja chica, conciliación— ya estaba declarado como evolución |
| 6 | Mesas/Layout + tiempo real | **Editor visual de planos (ADR-003) + piso en vivo con Reverb** |
| 7 | Promociones + Clientes/CFDI-ready | **Promociones + expediente de cliente y CFDI-ready** (el cliente mínimo ya existe) |
| 8–11 | Sin cambio | |

O sea: **once iteraciones pasan a diez**, y la que se elimina es la que quedaba vacía.

---

## 16. Lo que necesito de ti

**Aprobación explícita** antes de la primera migración, y en particular de estas seis cosas:

1. **El alcance ampliado de §1.5** — mesas operativas, gastos desde caja, crédito a clientes y liquidación de
   propinas entran con el POS.
2. **Lo que se queda fuera de §11**, sobre todo **las promociones** y el **editor visual de planos**. Son las
   dos que un «POS completo» podría reclamar, y mi argumento es que ninguna impide operar.
3. **Veintiocho tablas y veinte pasos en tres tandas**, como una sola iteración.
4. **La hoja de ruta reescrita de §15**: la iteración 5 se absorbe y quedan diez.
5. Que **`pos_accounts.waiter_membership_id` sea NOT NULL**: toda cuenta tiene titular, incluida una de barra
   que abrió el cajero. Si un negocio no tiene meseros, el titular es quien cobra.
6. Que **una cuenta con pagos no se pueda cancelar**, sólo corregir por reversa.

Y un aviso, no una pregunta: **esto es la iteración más grande del proyecto**, más o menos el doble de la 3.
Las tres tandas existen para que no haya un solo tramo largo sin verificar, que es el riesgo real. Si en
cualquier punto prefieres cortar y cerrar lo entregado, las tandas son los cortes naturales.
