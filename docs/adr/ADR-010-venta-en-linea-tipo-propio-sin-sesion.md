# ADR-010 — Venta en línea: tipo de movimiento propio, sin sesión de caja

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 8 (Menús digitales + E-commerce + pasarelas) |
| **Refina a** | ADR-007 (regla 4), ADR-004 |

> Precisa cómo se cumple la regla 4 de ADR-007 —«el pedido pagado genera venta y
> diario por eventos»— sobre el invariante de sesión de §6.3.

---

## Decisión

La venta de un pedido de e-commerce se asienta en el diario financiero con un **tipo
de movimiento propio, `OnlineSale`**, que **no exige sesión de caja**. El invariante de
§6.3 —«toda venta pertenece a una sesión»— se entiende como regla del **canal de
mostrador**: la venta en línea es un tipo distinto, deliberadamente fuera de ese
invariante, porque cobra por pasarela sin que se abra ningún cajón.

---

## Contexto

- ADR-007 (regla 4) fijó que un pedido pagado genera **venta y movimientos del diario
  por eventos de dominio**, igual que el POS, con la misma única fuente de verdad.
- El diario tiene un invariante de §6.3, codificado en `FinancialMovementType::
  requiresSession()` y hecho cumplir por `RecordFinancialMovement`: un movimiento de
  tipo `Sale` **siempre** pertenece a una sesión de caja, para que el arqueo pueda
  atribuirlo a un turno.
- Una venta de e-commerce **no tiene sesión ni turno**: el dinero entró por la
  pasarela, no por el cajón. No hay corte que la contenga.
- El diseño inicial del listener reutilizó el tipo `Sale` y pensó distinguir el canal
  sólo por `source_type = Order`. El invariante lo rechazó —correctamente—: es el mismo
  candado que atrapa una venta de mostrador registrada sin turno.

---

## Problema

Reutilizar `Sale` para la venta en línea obliga a **relajar el invariante de §6.3**:
o bien `Sale` deja de exigir sesión para todos (y entonces una venta de POS sin turno
—un bug real— dejaría de ser detectada), o bien se introduce una excepción condicional
dentro del invariante que acopla conceptos que no son lo mismo («sin actor de
personal» con «sin sesión»). En ambos casos se paga con el candado que protege la
LEY §6.3 para acomodar un canal nuevo.

---

## Alternativas

### A. Un tipo de movimiento propio, `OnlineSale`, que no exige sesión
- **A favor:** §6.3 queda literal e intacto para el mostrador; el canal es una consulta
  por tipo, no una interpretación del `source_type`; el `match` exhaustivo del enum
  obliga a definir su comportamiento en todos lados (signo, sesión, cajón, etiqueta).
- **En contra:** un tipo más en el catálogo; hay que tocar los tres `match` del enum y
  añadir el valor al `ENUM` de MySQL.

### B. Eximir del requisito de sesión a los asientos automáticos (sin actor)
- **A favor:** un solo tipo `Sale`; cambio quirúrgico.
- **En contra:** acopla «sin actor de personal» con «sin sesión», que son dos cosas
  distintas; una automatización futura que **sí** deba pertenecer a un turno se colaría
  sin ser detectada.

### C. Relajar `Sale` para que nunca exija sesión
- **A favor:** una línea.
- **En contra:** debilita globalmente el candado que atrapa ventas de mostrador sin
  turno —justo la LEY §6.3—. Inaceptable para material de §6.

---

## Decisión tomada

**Alternativa A.**

- `FinancialMovementType::OnlineSale` (`'online_sale'`): signo natural `+1` (suma como
  venta), `requiresSession() = false`, no mueve el cajón por naturaleza.
- El listener `RecordEcommerceOrderSale` asienta con este tipo, sin actor de personal
  (asiento automático) y sin sesión de caja, por el **subtotal** del pedido.
- Idempotente por (documento, tipo), como todo el diario: re-despachar el evento
  `EcommerceOrderPaid` no duplica la venta.

---

## Justificación

El invariante hizo su trabajo: rechazó el atajo. La respuesta correcta a un candado que
salta no es aflojarlo, es preguntarse si lo que intenta pasar es de verdad la misma
cosa. Y no lo es: una venta en línea es un hecho financiero genuinamente distinto —sin
registro, sin turno, sin cajón—. Nombrarlo con su propio tipo es honesto con el dominio
y, de paso, hace que «cuánto vendí en línea» se conteste sumando un tipo, en lugar de
filtrar por una cadena de `source_type`.

---

## Consecuencias

**Se gana**
- §6.3 sigue siendo literal para el mostrador: `Sale` exige sesión, sin excepciones.
- El canal (mostrador vs línea) es una dimensión de primera clase del diario, por tipo.
- El corte de caja ignora las ventas en línea **por construcción**: no tienen sesión,
  así que no entran en la suma de ningún turno.

**Se paga**
- Un tipo más en el catálogo cerrado; cada `match` exhaustivo sobre el enum debe
  contemplarlo (lo que el propio `match` sin `default` obliga a no olvidar).
- La lista `ENUM` de MySQL se amplía por migración, como se hizo con `promotion`.

**Simplificación declarada (v1)**
- Se asienta la **venta** (productos). El pago por pasarela **no** se journaliza como
  movimiento de caja —no hay corte en línea que cuadrar— y el **envío** no se separa
  aún como ingreso. Ambos son evolución, no deuda oculta.

**Reglas que quedan vigentes**
1. La venta de e-commerce se asienta como `OnlineSale`, **nunca** como `Sale`.
2. §6.3 «toda venta pertenece a una sesión» aplica al canal de mostrador. `OnlineSale`
   —como el gasto por transferencia y el depósito— queda fuera de `requiresSession`.
3. El corte de caja no incluye ventas en línea.
4. Una reversa de venta en línea conserva el tipo `OnlineSale` con signo contrario,
   como toda reversa del diario (el reembolso de e-commerce es Tanda D).
