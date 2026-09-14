# ADR-015 — Integración con marketplaces de delivery (DiDi Food, Uber Eats, Rappi)

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-09-13 |
| **Iteración** | Evolución (posterior a la hoja de ruta §14) |
| **Reemplaza a** | — |
| **Enmienda a** | ADR-010 (finanzas de la venta en línea) |

> **No reemplaza ninguna ADR.** Extiende la tienda en línea (ADR-007) con un **canal nuevo**: los pedidos
> que llegan desde un marketplace de delivery. Enmienda a **ADR-010** sólo en las finanzas del canal: la
> plataforma cobra y liquida después **menos su comisión**, así que aparece un movimiento tipado nuevo.

## Contexto

Un restaurante quiere recibir en Comandia los pedidos de **DiDi Food, Uber Eats y Rappi**, no sólo los de
su tienda propia. Cada plataforma cobra al cliente, asigna su repartidor y **retiene una comisión**; el
restaurante necesita que esos pedidos entren a **cocina e inventario** como cualquier otro, y que su
importe —neto de comisión— quede en el diario.

El motor de e-commerce ya es agnóstico del canal: aceptar un pedido emite `EcommerceOrderAccepted` y de
ahí reaccionan Inventario, Impresión y KDS (ADR-007); pagarlo emite `EcommerceOrderPaid` y Finanzas asienta
la venta (ADR-010). Un pedido de marketplace es, entonces, **otro canal que produce un `Order`** y entra al
**mismo pipeline**.

## Decisión

Se elige el **camino directo (por app)**: un adaptador por plataforma, sin middleware intermedio. Da más
control y evita la cuota de un agregador, a cambio de tres integraciones y su mantenimiento. La estructura
—una capa anti-corrupción por canal— es la misma que la de las pasarelas de pago, ya probada.

### 1. Capa anti-corrupción por canal

Contrato `MarketplaceChannel` (calcado de `PaymentGateway`) con adaptadores `DiDiFood`/`UberEats`/`Rappi` y
un `Fake` de pruebas, resueltos por `MarketplaceChannelFactory` (mapa estático, no registro en BD). Cada
adaptador `parseWebhook` (verifica firma y **normaliza** a un `IngestedOrder`) y `acknowledge` (avisa a la
plataforma). **Agregar un canal es implementar el contrato, no tocar la ingesta.**

### 2. Configuración por sucursal y canal (encender/apagar)

`delivery_channel_settings`, una fila por **(sucursal, canal)** —espejo de `PaymentGatewaySetting`, pero por
sucursal porque cada una es una "tienda" en la plataforma—: `is_active` (encender/apagar), `external_store_id`,
secretos **cifrados** y `commission_rate`. API de admin gateada por `module:Ecommerce` +
`ecommerce.store.configure`. Un canal apagado o sin credenciales no opera. **Depende del registro previo del
restaurante en cada plataforma** (convenio + credenciales): sin eso, el canal no ingiere.

### 3. Ingesta por webhook

`POST /t/{slug}/webhook/marketplace/{channel}` (público, firma verificada, exento de CSRF como los webhooks
de pago). El slug resuelve el tenant (`ResolvesPublicStore`). `IngestMarketplaceOrder` mapea los ítems
externos a artículos (`marketplace_menu_maps`), **congela precios del catálogo** (no lo que reporte la
plataforma), crea el `Order` (`delivery_type = marketplace`), lo marca **pagado** (el cobro es externo) y lo
**auto-acepta** (sale a cocina). **Idempotente** por `(channel, external_order_id)`: un webhook reenviado no
duplica. Un ítem sin mapear se rechaza (422).

### 4. Reuso del pipeline

La ingesta emite `EcommerceOrderPaid` y `EcommerceOrderAccepted` (kernel), así que **Finanzas, Inventario,
Impresión y KDS reaccionan sin cambios**: la comida se rutea a cocina y descuenta inventario igual que un
pedido de la tienda. El cliente se resuelve por teléfono o se crea uno "invitado" (D43); no hay cuenta en
Comandia.

### 5. Finanzas: comisión (enmienda a ADR-010)

La venta se asienta al **bruto** como `OnlineSale`. La comisión que la plataforma retiene se asienta como un
tipo **propio** `MarketplaceCommission` (signo negativo, sin sesión ni actor, como la venta en línea), que la
**netea**. Tipo propio y no `Expense` para que «cuánto me costó cada canal» sea una consulta por tipo. Lo
emite `MarketplaceCommissionCharged` (kernel) y lo asienta `RecordMarketplaceCommission`. Idempotente por
(documento, tipo): coexiste con el `OnlineSale` del mismo pedido.

## Fases

- **Fase 1 (esta):** contrato + factory + **adaptador `fake`**, `delivery_channel_settings` (on/off + config
  por sucursal) con su API de admin, mapeo de menú, ingesta por webhook idempotente, `delivery_type=marketplace`,
  y la comisión (enmienda a ADR-010). Todo verificable **sin credenciales reales**, contra el adaptador falso.
- **Fase 2:** publicación del menú saliente (mapear artículos→esquema de cada plataforma) y su pantalla de mapeo.
- **Fase 3+:** cablear DiDi Food, Uber Eats y Rappi reales conforme llegue el convenio/credenciales de cada uno.

## Consecuencias

- **Positivas.** Los pedidos de agregador entran a cocina/inventario reusando el motor; la venta queda neta de
  comisión en el diario; encender un canal por sucursal es config, no código. La superficie de daño de un
  secreto robado se limita a ese canal y esa sucursal.
- **Costo.** Tres adaptadores + tres altas de socio + mantenimiento de tres APIs que cambian por su cuenta
  (es el precio del camino directo). El menú saliente es esfuerzo por plataforma (Fase 2/3).
- **Deuda declarada.** Los adaptadores reales lanzan 503 ("no cableado") hasta la Fase 3. Sin el convenio y
  las credenciales de cada plataforma no hay prueba de punta a punta contra el real; Fase 1 se prueba contra
  el adaptador falso y los contratos documentados. La disambiguación por sucursal cuando un canal atiende a
  varias (por `external_store_id` en la ruta/encabezado) se afina en Fase 2.

## Alternativas consideradas

1. **Vía middleware** (Deliverect/Otter/Cuboh/NubeRest): una sola integración para los tres, menos código y
   mantenimiento, a cambio de una cuota y una dependencia externa. **Descartada** por decisión del negocio:
   se prefiere el control del camino directo.
2. **Comisión como `Expense`** en vez de tipo propio. Descartada: perdería la consulta «cuánto me costó cada
   canal» por tipo, que es justo lo que ADR-004 busca con el catálogo cerrado.
