# ADR-013 — Modo de tienda: preparación (A&B) o envío (retail), con entrega configurable

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-09-12 |
| **Iteración** | Evolución (posterior a la hoja de ruta §14) |
| **Reemplaza a** | — |

> **No reemplaza ninguna ADR.** Extiende la tienda en línea (ADR-007, Iteración 8) con un **modo de
> fulfillment** y **opciones de entrega configurables**, y enmienda la premisa A&B de la
> `ESPECIFICACIÓN_MAESTRA` §1 **sólo para la superficie de tienda en línea**: Comandia sigue siendo un
> sistema de A&B, pero su tienda puede operar en modo **envío** para giros que despachan después (p. ej.
> una ferretería). Preserva D48 (e-commerce por sucursal), D51 (auto-aceptar), D269 (entrega ⊥ pago) y
> D338 (inventario al aceptar).

---

## Decisión

La tienda en línea gana un `fulfillment_mode`:

- **preparación** — A&B: al aceptar se genera comanda a cocina y la entrega es inmediata (pickup /
  entrega local). Es el comportamiento actual.
- **envío** — retail: el pedido se acepta, se **empaca** y se **envía después**, **sin comanda de
  cocina**.

Además, cada tienda declara qué **opciones de entrega** ofrece (`ofrece_pickup`, `ofrece_envío`). El
modo envío añade **estados de fulfillment ligeros** (empacado → enviado → entregado) con
paquetería/guía/fecha **opcionales**. El motor del pedido, el ciclo financiero y el inventario no
cambian.

---

## Contexto

- Comandia es SaaS de **alimentos y bebidas** (Especificación §1). La tienda en línea (ADR-007, It. 8)
  hoy asume A&B en la **narrativa** ("preparar/listo/cocina") y en la transición **`aceptado`**, que
  emite la comanda a cocina.
- **El motor ya es agnóstico del giro** (confirmado en el código): el inventario descuenta el artículo
  *inventariable-no-producible* (`DeductSoldItems`), y el pipeline de comandas **se auto-anula** cuando
  el negocio no tiene áreas de preparación (`AreaRouter` → `null` por línea; los listeners de KDS e
  impresión no encolan nada). Una ferretería **ya opera mecánicamente**; lo que falta es configuración,
  narrativa y estados de envío.
- **Estado hoy** (código): `stores` = `slug/name/is_active/theme_primary/auto_accept_orders` +
  `store_branches`. `delivery_type` es un enum fijo `pickup|shipping` (pickup **no se puede apagar**;
  envío disponible sólo si existe una `ShippingZone` activa). Estados del pedido
  `pending_payment → paid → accepted → ready → completed` (+ `failed/rejected/cancelled`); **sin
  carrier/guía/fecha de envío**. La config de la tienda vive en sus tablas, no en `SettingCatalog`.
- **Pedido del usuario (2026-09-04):** pickup configurable, tipo de tienda configurable, y soporte a
  giros no-A&B con **envío posterior** (ejemplo: ferretería).

---

## Problema

El modelo actual (1) no deja **apagar pickup** ni declarar qué entregas ofrece la tienda; (2) **narra**
todo como cocina/preparación aunque el giro no cocine; y (3) no **representa un envío real** (guía,
fecha). Para una ferretería que vende en línea y despacha después, la tienda "funciona" pero miente en
la narrativa y no registra el envío.

---

## Alternativas consideradas

- **Enfoque del soporte no-A&B:**
  - **(A1) Modo de tienda como configuración *— elegida*.** Comandia sigue siendo A&B; la tienda gana un
    modo que adapta flujo, narrativa y defaults. Cambio contenido al módulo Ecommerce.
  - (A2) Generalizar el producto a retail (giro/vertical de primera clase; renombrar
    Orden/Comanda/Cuenta; plantillas por giro). **Descartada:** alcance enorme, contradice la identidad
    del spec, sin necesidad probada.
- **Profundidad del "envío posterior":**
  - **(B1) Estados ligeros + guía/carrier/fecha opcionales *— elegida*.** Sin integración de
    transportistas.
  - (B2) Reinterpretar `ready/completed` como empacado/entregado sin campos nuevos. **Descartada:** no
    registra guía ni fecha, que es justo lo que un envío pide.
  - (B3) Integración de paquetería (cotización, guías, tracking por webhook). **Futura**, se apoya sobre
    B1.

---

## Diseño

### Entidades / datos

1. **`stores`** (+3 columnas):
   - `fulfillment_mode` — string NOT NULL default `'preparation'`; valores `preparation` | `dispatch`
     (enum de dominio `StoreFulfillmentMode`). *No es* `shipping` para no chocar con `delivery_type`.
   - `offers_pickup` — bool NOT NULL default `true`.
   - `offers_shipping` — bool NOT NULL default `false`.
   - **Invariante:** al menos una opción de entrega encendida (validado en el Form Request).
2. **`orders`** (+3 columnas, nullable — sólo se llenan en modo envío):
   - `carrier` string(80) nullable — paquetería (texto libre).
   - `tracking_number` string(120) nullable — número de guía.
   - `shipped_at` timestamp nullable — cuándo se envió.
   - *Sin índices nuevos:* siempre se consultan por el pedido ya acotado.

### Máquina de estados (extensión de `OnlineOrderStatus`)

- Compartidos: `pending_payment, paid, accepted, failed, rejected, cancelled`.
- **Preparación:** `accepted → ready → completed` (como hoy; `ready` = listo para recoger/entregar).
- **Envío (nuevos estados):** `accepted → packed → shipped → completed` — `packed` (empacado),
  `shipped` (enviado; fija `shipped_at`, admite `carrier`/`tracking_number`), `completed` (entregado).
- Transiciones **legales** en el enum (agnóstico del modo): `accepted → {ready | packed}`,
  `ready → completed`, `packed → shipped`, `shipped → completed`. El **modo de la tienda** y la bandeja
  eligen el camino; el enum sólo declara qué es legal (el candado de transiciones cubre ambos).

### Comanda en modo envío

En modo `dispatch`, `PlaceOrder` **no congela** `preparation_area_id` (salta `AreaRouter`); al aceptar,
**no se emite comanda a cocina** (los listeners ya no-opean sin áreas). El inventario **sí** se descuenta
(almacén de la sucursal). Es explícito e intencional por el modo, no una casualidad de datos.

### Checkout / entrega

`CheckoutRequest` valida `delivery_type` contra las opciones de la tienda: `pickup` sólo si
`offers_pickup`; `shipping` sólo si `offers_shipping` (y con zona activa). El catálogo público sólo
ofrece las habilitadas.

### Configuración de admin

`SaveStoreRequest` / `ManageStore` / `Admin/Store/Index.vue`: selector de **modo** + toggles de
**pickup**/**envío**, con la invariante "al menos una". Permiso `ecommerce.store.configure`. La
**bandeja de pedidos** (`Orders.vue` / `OrderTrayController`) ramifica acciones y narrativa por el modo
(**Preparar/Listo** vs **Empacar/Enviar/Entregado**), capturando `carrier`/`tracking_number` al marcar
*enviado*. Permisos de bandeja existentes (`ecommerce.orders.accept`, …).

### Compatibilidad

Las tiendas existentes se rellenan por migración a `fulfillment_mode='preparation'`,
`offers_pickup=true`, `offers_shipping=(existe alguna zona activa)`, para **no cambiar** su
comportamiento actual.

---

## Consecuencias

- (+) Una ferretería vende en línea con narrativa y estados correctos; A&B queda intacto.
- (+) Cambio **contenido** al módulo Ecommerce; Core, finanzas e inventario sin tocar (ADR-007 se
  respeta: los efectos siguen por eventos del kernel).
- (−) Bandeja y checkout ganan ramas por modo (más superficie de UI y de pruebas).
- (−) `OnlineOrderStatus` crece 2 estados; el candado de transiciones debe cubrir ambos caminos.
- **Deuda declarada:** sin integración de paquetería (B3); `carrier`/`tracking_number` son texto manual.

---

## Definition of Done

- **Unit** del enum: transiciones legales de ambos caminos; las ilegales se rechazan.
- **Feature:** el checkout respeta las opciones (pickup apagado → 422; envío sin zona → 422). Modo
  envío: aceptar **no** genera comanda pero **sí** descuenta inventario; bandeja
  `packed → shipped → completed` con `carrier`/`tracking_number`/`shipped_at`; auto-aceptar sigue
  funcionando. Aislamiento de tenant.
- **Migración** con relleno de tiendas existentes y constraints reales (NOT NULL/default).
- **Form Requests** para toda entrada; **Resources** para toda salida; sin lógica crítica en Vue.
- Documentación de la iteración actualizada.
