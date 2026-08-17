# ADR-007 — Frontera E-commerce / Core: la publicación es una capa, no una copia

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 9 (Menús digitales + E-commerce + pasarelas) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §13 y ESPECIFICACION_MAESTRA §6.8 (D48, D49, D51).

---

## Decisión

La tienda en línea y los menús digitales son una **capa de publicación** sobre el
catálogo del Core: **una sola fuente de verdad** para artículos, precios, clientes,
inventario y ciclo financiero. E-commerce sólo posee lo que es exclusivamente suyo:
carrito, checkout, pasarelas, estados de pedido, entrega, promociones de tienda,
configuración visual y URL pública.

---

## Contexto

- E-commerce y menús digitales son **módulos activables por tenant** (D3): un tenant
  sin ellos no ejecuta una sola línea de su código
  (ARQUITECTURA_MAESTRA §2, regla 4).
- El cliente elige sucursal al comprar; si hay una sola, se toma por defecto (D48).
- Regla de negocio asimétrica y deliberada: **la tienda SÍ respeta stock**
  (configurable por artículo: vender siempre / ocultar / marcar agotado), mientras
  que **el POS nunca se bloquea por inventario** (§6.2).
- Un pedido pagado entra a una **bandeja de aceptación** y de ahí genera comandas
  —la aceptación automática es configurable— (D51).
- Pasarelas: contrato único con dos implementaciones, Mercado Pago y Stripe, **una
  activa a la vez** por tenant, credenciales cifradas (D49).
- El Core ignora la existencia de los módulos activables.

---

## Problema

La tentación estructural de todo e-commerce sobre un ERP es mantener su propio
catálogo: "los productos de la tienda". Es cómodo —la tienda necesita descripciones
largas, galerías, SEO y orden de aparición que el POS no usa— y es la fuente de la
falla más común y más visible del producto: el precio de la tienda no coincide con el
del mostrador, o un artículo que se dejó de vender sigue publicado.

El problema simétrico es meter en el Core todo lo que la tienda necesita: el catálogo
se llena de campos de SEO y de configuración visual que a la administración del
restaurante no le importan, y la frontera del módulo activable desaparece.

---

## Alternativas

### A. Catálogo propio de e-commerce sincronizado con el Core
- **A favor:** independencia total de la tienda; puede modelar lo que quiera.
- **En contra:** sincronización permanente, con su ventana de inconsistencia; dos
  precios para el mismo artículo; la falla es visible para el cliente final y
  costosa en confianza.

### B. Capa de publicación sobre el catálogo del Core
- **A favor:** el precio y la disponibilidad son los mismos por construcción; el
  atributo de publicación —descripción larga, galería, orden, SEO— se agrega sin
  contaminar el Core; el ciclo financiero es uno.
- **En contra:** e-commerce depende de la forma del catálogo del Core; un cambio en
  el Core puede afectar la tienda; hay que definir con cuidado qué es "publicación"
  y qué es "catálogo".

### C. Meter los atributos de tienda dentro del catálogo del Core
- **A favor:** simplísimo, sin capa intermedia.
- **En contra:** rompe la frontera del módulo activable; el Core carga con conceptos
  de un módulo que la mayoría de tenants no contrata.

---

## Decisión tomada

**Alternativa B.**

**Compartido con el Core (una sola fuente de verdad):**
- **Artículos:** la capa de publicación **agrega** descripción larga, galería, orden
  y SEO. No duplica el artículo.
- **Precios:** los del Core, con override **por canal** opcional (POS /
  e-commerce). Un override es un dato del Core, no un precio paralelo de la tienda.
- **Clientes:** los mismos del tenant.
- **Inventario:** el del Core. La tienda **respeta stock** con política por artículo:
  vender siempre / ocultar / marcar agotado.
- **Ciclo financiero:** pedido pagado → venta y movimientos del diario con canal
  `e-commerce`, por los mismos eventos de dominio que el POS.

**Exclusivo de e-commerce:**
carrito, checkout, pasarelas, estados de pedido, entrega (pickup o envío por zona),
promociones y cupones de tienda, configuración visual y URL pública (`/t/{slug}`).

**Menús digitales** son la misma idea sin transacción: menú QR por sucursal
(`/m/{slug}`) y PDF desde plantillas parametrizables (colores, logo, tipografía). El
editor libre de plantillas es evolución.

**Pasarelas:** un contrato único —crear pago, webhook, reembolso— con dos
implementaciones. Una activa a la vez por tenant; credenciales cifradas en reposo;
cada pago registra con qué pasarela se hizo. Agregar una pasarela es implementar el
contrato, no tocar el checkout.

---

## Justificación

La asimetría de la regla de stock es la mejor prueba de que la frontera está bien
puesta: el POS no se bloquea porque el mesero tiene el plato enfrente y la venta ya
ocurrió; la tienda sí se bloquea porque prometer algo que no existe a alguien que ya
pagó es un problema distinto. **Misma existencia, misma fuente de verdad, políticas
distintas por canal.** Eso sólo se puede modelar limpio si hay un solo inventario.

Y la razón por la que la publicación es capa y no copia: el precio incorrecto en la
tienda es el error que el cliente final ve, y en el modelo de A es inevitable —sólo se
puede hacer más raro—.

---

## Consecuencias

**Se gana**
- Imposible que el precio de la tienda difiera del de mostrador por falta de
  sincronización.
- Un solo inventario y un solo ciclo financiero: el reporte de ventas incluye
  e-commerce sin conciliación.
- Agregar una pasarela es implementar un contrato.
- Un tenant sin el módulo no ejecuta ni una línea de este código.

**Se paga**
- E-commerce está acoplado a la forma del catálogo del Core; los cambios del Core
  deben considerarlo.
- Hay que mantener la disciplina de decidir, para cada atributo nuevo, si es catálogo
  o es publicación.
- El Core no puede evolucionar su catálogo ignorando a los módulos activables, aunque
  tampoco puede depender de ellos.

**Reglas que quedan vigentes**
1. Prohibido duplicar el catálogo. La publicación **agrega** atributos, nunca copia
   artículos.
2. El precio de e-commerce es el del Core con override por canal. No existe una tabla
   de precios de tienda.
3. La tienda respeta stock según la política del artículo. El POS nunca se bloquea.
   Ambas reglas conviven sobre el mismo inventario.
4. El pedido pagado genera venta y diario **por eventos de dominio**, igual que el
   POS: e-commerce no escribe en finanzas (ADR-004).
5. El Core no referencia a `DigitalMenus` ni a `Ecommerce`. Ellos consumen los
   servicios públicos del Core.
6. Credenciales de pasarela cifradas en reposo, jamás en logs. Webhooks con firma
   verificada (D55).

**Puerta de salida**
E-commerce es uno de los módulos explícitamente considerados extraíbles en ADR-001.
Extraerlo exigiría convertir su lectura del catálogo en consumo de API y su escritura
financiera en publicación de eventos remotos —que es exactamente la frontera que esta
ADR ya impone—. La señal: carga pública que degrade la operación del POS.
