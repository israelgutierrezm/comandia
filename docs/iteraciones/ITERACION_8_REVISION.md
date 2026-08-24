# Iteración 8 — Revisión de cierre

**Menús digitales + E-commerce + pasarelas.** Decisiones D326–D344, ADR-010. Suite completa en verde (1356 pruebas).

> Cierra el ciclo de la tienda en línea de extremo a extremo: publicar → navegar → carrito → checkout → cobrar (prueba y
> Stripe/Mercado Pago reales) → aceptar (con comandas de cocina e inventario) → entregar, más rechazo/reembolso y cupones.

---

## 1. Qué quedó construido

| Tanda | Contenido | Decisiones |
|---|---|---|
| **A** | Menús digitales: menú QR por sucursal (`/m/{slug}`), PDF, **capa de publicación** (módulo `Publishing` compartido) | D326–D329 |
| **B** | Tienda: catálogo publicado + carrito + **override de precio por canal** + subconjunto configurable de sucursales | D330–D332 |
| **C1** | Cuentas de cliente: `Customer` autenticable, guard `customer`, registro/login públicos por slug | D333 |
| **C2** | Pedido foliado (`WEB-…` sin huecos) + entrega pickup / envío por zona | — |
| **C3a** | Contrato `PaymentGateway` + `FakeGateway` + webhook idempotente + ciclo financiero por eventos: **`OnlineSale`** sin sesión ni actor | ADR-010, D334–D336 |
| **C3b** | Mercado Pago y Stripe reales (sin SDK), con verificación de firma | — |
| **D1** | Máquina de estados del pedido + bandeja de aceptación + sonda **`AreaRouter`** + inventario al aceptar + auto-aceptación; **comandas** por área (impresión + pantalla de cocina, reuso de Printing/Pos) | D337–D340 |
| **D2** | Rechazo + **reembolso** (prueba y Stripe/Mercado Pago reales) + reversa de la `OnlineSale` + estados de entrega | D341, D344 |
| **D3** | **Cupones**: entidad + administración + canje en el checkout (%/monto/envío gratis, vigencia, topes) | D342–D343 |

**Nuevas piezas del kernel:** sonda `AreaRouter` (cuarta con el patrón de inversión), eventos `EcommerceOrderPaid` /
`EcommerceOrderAccepted` / `EcommerceOrderRefunded`, tipo de movimiento `OnlineSale`. Módulos activables `Ecommerce` y
`DigitalMenus`, y el compartido `Publishing`.

---

## 2. Lo que salió bien

- **ADR-010 nació de un candado que hizo su trabajo.** El diseño inicial reutilizó el tipo `Sale` para la venta en línea y
  el invariante de §6.3 (una venta pertenece a una sesión de caja) lo rechazó. En vez de aflojar el candado del mostrador se
  nombró un tipo propio, `OnlineSale`, sin sesión ni actor. El invariante atrapó el atajo; se le hizo caso.
- **`AreaRouter` mantuvo la frontera.** La tienda parte sus pedidos en comandas por área reusando el ruteo del POS sin
  depender de `Pos`: una sonda del kernel que `Pos` implementa. La pantalla de cocina no cambió una sola línea (mismo canal
  y evento `AreaOrderCommanded`).
- **Descontar el inventario al ACEPTAR (D338) fue el pago limpio.** Un pedido rechazado nunca movió stock, así que el
  rechazo no necesita reversa de kardex. La decisión de mover el descuento del pago a la aceptación simplificó toda la
  Tanda D.
- **Un contrato, tres pasarelas.** Agregar Mercado Pago y Stripe fue implementar `PaymentGateway`; el checkout y el webhook
  no cambiaron (ADR-007).

---

## 3. Lo que salió mal (y cómo se atrapó)

- **Dos regresiones al hacer el actor del asiento nullable**, atrapadas por la suite completa —no por el archivo de la
  tanda—: `CheckoutTest` (el checkout ahora exige pasarela, así que fallaba sin una configurada) y `FinancialJournalTest`
  (el candado del actor se había movido de la base al servicio). Las dos se arreglaron con pruebas que muerden. Confirma la
  regla de la memoria: correr la suite completa antes de cada commit, no sólo el archivo tocado.
- **`actingAsSpa` tras autenticar a un cliente dejaba el guard por omisión en `customer`**, y el personal quedaba
  autenticado en el guard equivocado → 401. Se repuso `web` **en las pruebas de e-commerce**, no en el ayudante compartido:
  fijar el guard en el ayudante rompía el patrón de dos-tenants de `StoreAdminTest` (comprobado empíricamente antes de
  decidir).
- **La reversa del diario fue el primer uso del mecanismo `reverses`** y chocó con la idempotencia por (documento, tipo): la
  venta ya ocupaba `(Order, pedido, OnlineSale)`. Se ancló la reversa en un documento distinto —el pago de reembolso— y se
  enlazó a la venta por `reverses_movement_id`.
- **Hueco pre-existente del descuento de inventario:** un platillo cuya receta usa un sub-producible **no inventariable**
  («Salsa verde») no descuenta su inventario (el mismo `DeductSoldItems` del POS lo rechaza). Surgió al comprar el platillo
  en la demo; es del descuento del POS, no del e-commerce. Queda como tarea aparte (chip).
- **La verificación en navegador confirmó el flujo público** (registrar cliente → carrito → checkout → pago simulado →
  pedido pagado, con la venta y el inventario correctos). Las pantallas de personal (bandeja, cupones, pasarela) exigen
  login del dueño y quedan a su verificación.

---

## 4. Las dos preguntas obligatorias de cierre

### 4.1 ¿Qué endpoints de los módulos construidos no se han llamado nunca en una prueba?

**Ninguno.** `EveryEndpointIsExercisedTest` está en verde: cada ruta de `/api/v1` de la Iteración 8 (tienda, ajustes por
artículo, zonas de envío, pasarela, bandeja de pedidos con aceptar/rechazar/listo/entregado, cupones) se ejercita al menos
en una prueba. Las superficies públicas (`/t/{slug}`, `/m/{slug}`, webhook) se cubren con sus pruebas de feature.

### 4.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

Sólo **`reporting.exports.create`**, colgado desde la Iteración 7 (revisión manual, no candado: el catálogo declara
permisos de iteraciones futuras a propósito). Todos los permisos de la Iteración 8 tienen ruta:
`ecommerce.store.configure`, `ecommerce.orders.view/accept/reject`, `ecommerce.gateways.configure`,
`ecommerce.coupons.manage`, `ecommerce.shipping_zones.manage`, `digital_menus.menus.manage`, `digital_menus.pdf.generate`,
`publishing.articles.manage`, `tenancy.modules.view/manage`.

---

## 5. Lo que se lleva la Iteración 9

### 5.1 Decisiones pendientes del dueño del producto

- **`reporting.exports.create`**: quitarlo del catálogo o darle ruta. Lleva dos iteraciones colgado.

### 5.2 Deuda técnica declarada

- **Descuento de inventario de platillos con sub-receta no inventariable** (chip): decidir entre recursar el resolutor,
  marcar el sub-producible como inventariable, o validar la receta al guardarla.
- **Topes de cupón best-effort bajo concurrencia:** el tope global y el límite por cliente se comprueban antes de la
  transacción; dos canjes simultáneos del último uso podrían pasar juntos. Sin lock en v1 (el pedido y el cobro no se ven
  afectados).
- **Vigencia de cupón en fecha del servidor**, no en la zona de la sucursal (las promociones de la It.6 sí usan la zona).
- **El pago por pasarela y el envío no se journalizan** como movimiento de caja ni como ingreso separado (ADR-010): se
  asienta la venta de productos, neta de cupones. Evolución declarada.
- **Cancelación por el cliente y rechazo de un pedido ya aceptado** (con reversa de kardex y cocina): fuera de v1.

### 5.3 Lo que la Iteración 9 (App Flutter) puede dar por hecho

- El ciclo de e-commerce completo, por eventos del kernel, sin que ningún módulo del Core nombre a `Ecommerce`.
- El **contrato de payload de impresión** (datos, no texto) que ya sirve a comandas del mostrador y de la tienda por igual:
  es el que el puente de impresión de Flutter consumirá.
- Las sondas del kernel (`AreaRouter`, `StockAvailabilityProbe`, …) como el patrón para que un módulo pregunte al Core sin
  acoplarse.

### 5.4 La lección de esta iteración

Un invariante que salta no es un estorbo que aflojar: es una pregunta sobre si lo que intenta pasar es de verdad lo mismo.
La venta en línea no era una venta de mostrador sin caja —era otra cosa—, y nombrarla (`OnlineSale`) resultó más limpio que
relajar §6.3. Lo mismo con el actor del asiento y con la reversa del diario: cada tensión con una regla existente se resolvió
nombrando el caso nuevo, no debilitando la regla.
