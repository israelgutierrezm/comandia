# ADR-011 — Elementos de salón no vendibles (muros, puertas, rótulos)

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-08-31 |
| **Iteración** | Rediseño del editor de salón (posterior a Iteración 6) |
| **Reemplaza a** | — (refina ADR-003) |

---

## Decisión

El plano de salón admite **elementos decorativos no vendibles** —muros/columnas, puertas
y rótulos— en una tabla propia `floor_elements`, con las **mismas coordenadas lógicas y
el mismo componente de render** que las mesas (ADR-003), pero **sin código, capacidad,
estado ni cuenta**; se editan en el editor y se muestran, sin interacción, en el piso de
venta.

---

## Contexto

- ADR-003 fija el editor y el piso en SVG + Vue puro, con coordenadas lógicas y un solo
  componente de render. Hoy el plano contiene únicamente **mesas** (`restaurant_tables`)
  y **zonas** (`floor_zones`).
- El rediseño del editor (mockups) pide un "Agregar elemento" para dibujar el local real
  —muros, columnas, entradas— y anotarlo, de modo que el mesero se oriente de un vistazo.
- Reglas de datos del proyecto: sin JSON en dominio; tablas en plural inglés; PK interna
  BIGINT + ULID público; `tenant_id` NOT NULL en toda tabla de dominio (ADR-002).
- Una mesa tiene invariantes que un muro no tiene: código único por sucursal, capacidad,
  estado movido por sus cuentas (§6.3), foliación, y `RESTRICT` desde `pos_accounts`.

---

## Problema

Sin una decisión, "Agregar elemento" se implementaría de la forma fácil y equivocada:
metiendo los muros como filas de `restaurant_tables` con un flag discriminador. Eso
obliga a **filtrar el flag en TODA consulta de mesas** —el piso, «mesas disponibles», la
foliación, la unión de mesas— y el día que una la olvide, **un muro aparece como mesa
sentable** o, peor, recibe una cuenta. ADR-003 quedaría contradicha en silencio y el
fallo aparecería en operación, con el restaurante lleno.

---

## Alternativas

### A. Extender `restaurant_tables` con una columna `kind`
- **Qué implica:** una columna discriminadora (`table`|`wall`|…); los muros son filas de
  mesas sin código ni capacidad.
- **A favor:** reutiliza geometría, render y el guardado de layout sin tabla nueva.
- **En contra:** contamina la entidad más cargada de invariantes del dominio.
  `code`/`seats`/`status` pasan a "nullable según kind"; toda consulta de mesas debe
  recordar filtrar `kind='table'`; y la FK `pos_accounts.table_id` (RESTRICT) apunta a una
  tabla que ya no es sólo de mesas. El olvido de un filtro es un muro sentable en producción.

### B. Tabla propia `floor_elements`
- **Qué implica:** entidad separada con geometría (coordenadas lógicas) + `kind` + texto
  opcional, sin código, capacidad, estado, cuenta ni zona.
- **A favor:** la mesa queda intacta con sus invariantes; los elementos tienen ciclo
  simple —se **borran** de verdad, no se archivan: un muro no tiene historial que
  preservar—; el render los dibuja detrás de las mesas; una consulta de mesas nunca ve un
  elemento.
- **En contra:** una tabla y un CRUD más; el render y el guardado de layout deben conocer
  dos colecciones (mesas + elementos).

### C. No hacerlo
- **Qué implica:** se descarta "Agregar elemento".
- **A favor:** cero trabajo.
- **En contra:** el plano no refleja el local y se pierde la orientación que el rediseño
  busca. Es una decisión de producto ya tomada (se quiere).

---

## Decisión tomada

**Alternativa B.** Tabla `floor_elements`.

**Dentro:**
- `floor_elements`: `id` BIGINT, `ulid` (expuesto por API), `tenant_id` (FK a `tenants`,
  cascade), `floor_plan_id` (FK a `floor_plans`, cascade), `kind` enum(`wall`,`door`,`label`),
  `text` varchar(120) nullable (sólo para `label`), geometría `x`/`y`/`width`/`height`
  DECIMAL(8,2) + `rotation` DECIMAL(5,2), `sort_order` unsignedInteger default 0, timestamps.
  `unique(ulid)`; CHECK de dimensiones positivas. El acceso es por la FK de `floor_plan_id`
  (se cargan por plano, con su plano); `tenant_id` va por ADR-002 aunque sea alcanzable por FK.
- Enum **cerrado** de tres tipos: `wall` (muro/columna, rectángulo), `door` (puerta,
  marcador) y `label` (rótulo de texto). Ampliarlo (planta, barra decorativa) es una
  migración futura, no una columna libre.
- API (módulo `Floor`, permiso `floor.layouts.edit`): `POST /floor-plans/{plan}/elements`,
  `PATCH /floor-elements/{element}`, `DELETE /floor-elements/{element}`. El guardado en
  bloque `PUT /floor-plans/{plan}/layout` se **extiende** para incluir la geometría de los
  elementos junto a la de las mesas (mover mesas y muros es un solo acto). Form Requests para
  la entrada, Resource para la salida, whitelist de campos.
- Render (FloorCanvas, ADR-003): los elementos se dibujan **detrás** de las mesas; `wall`
  con relleno gris tenue, `door` como un hueco/arco, `label` como texto. En el editor son
  movibles/redimensionables con la misma maquinaria de gestos; **no son seleccionables como
  cuenta**. En el piso de venta (lectura) se muestran para orientar, sin interacción.
- Los elementos se **borran de verdad** (DELETE), no se archivan: a diferencia de una mesa,
  ningún documento histórico apunta a un muro.

**Fuera / sin resolver (otra decisión, no bloquea ésta):**
- Elementos con color propio o una biblioteca de iconos decorativos: el estilo lo fija el
  `kind`.
- Adherencia/snap entre elementos y mesas: la cuadrícula de 10 cm que ya existe basta.
- Puertas con semántica de aforo (entradas/salidas): `door` es decorativo en v1.

---

## Justificación

Prioridades del proyecto (ESPECIFICACION_MAESTRA §1: correctitud > seguridad >
mantenibilidad > escalabilidad > rendimiento > velocidad). Correctitud y mantenibilidad
mandan aquí: la alternativa A sacrifica ambas por ahorrarse una tabla. El costo real de A
no es la columna `kind` —es que **cada consulta de mesas del sistema queda a merced de
recordar filtrar**, y el fallo (un muro sentable, una cuenta sobre un muro) aparece en
operación, no en una prueba—. B paga una tabla y un CRUD a cambio de que la mesa conserve
sus invariantes intactos.

Refina ADR-003 sin contradecirla: conserva su decisión central —SVG + Vue, coordenadas
lógicas, un solo render— y sólo amplía el modelo de "mesas" a "mesas + elementos".

---

## Consecuencias

**Se gana**
- El plano refleja el local (muros, puertas, rótulos); mejor orientación en el piso.
- La mesa conserva sus invariantes; ninguna consulta de mesas ve un elemento.
- Elementos con ciclo de vida simple (se borran), sin cargar el modelo de mesa.

**Se paga**
- Una tabla, un modelo, un CRUD y su test de aislamiento más.
- FloorCanvas y el guardado de layout conocen dos colecciones en lugar de una.

**Reglas que quedan vigentes** (verificables en revisión o por test)
1. Un muro/puerta/rótulo **nunca** es una fila de `restaurant_tables`; vive en `floor_elements`.
2. `floor_elements` no tiene código, capacidad, estado ni cuenta; no participa en foliación
   ni en «mesas disponibles».
3. El enum `kind` es cerrado (`wall`,`door`,`label`); ampliarlo es una migración.
4. Los elementos se renderizan **detrás** de las mesas y no son seleccionables como cuenta.
5. `tenant_id` NOT NULL + global scope + test de aislamiento (ADR-002).

**Puerta de salida**
Si algún día un elemento necesitara estado o interacción —una puerta que cuenta aforo, una
barra que es punto de venta—, dejaría de ser "decorativo" y merecería su propia entidad con
esos invariantes, o volvería a acercarse a la mesa; sería una ADR nueva. La señal es el
primer requisito que pida que un elemento **haga algo** además de dibujarse.
