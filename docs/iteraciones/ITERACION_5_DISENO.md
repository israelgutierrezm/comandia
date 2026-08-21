# Iteración 5 — Mesas/Layout visual + tiempo real

**Estado:** **APROBADO** el 2026-08-21. Las cinco decisiones de §9 quedaron aprobadas tal como están.

Y las tres que dejó abiertas la revisión de la Iteración 4:

| Pregunta | Decisión |
|---|---|
| ¿Cobrar a crédito exige `pos.credit.charge_to_customer`? | **Sí, se exige** (D296). Es lo que el catálogo y las plantillas de rol ya suponían |
| ¿Se construye la reimpresión de comandas? | **Sí, en esta iteración** (D297). Entra en la tanda C, junto a la pantalla de comandas |
| ¿Qué pasa con `finance.cuts.close`? | **Se elimina del catálogo** (D298). Cerrar el turno ya exige `pos.sessions.close` |

**Alcance de la hoja de ruta (§14, renumerada tras D235):** editor visual de planos (ADR-003) y piso en vivo con
Reverb.

---

## 0. Qué existe ya, y por qué esta iteración es más pequeña que la anterior

La Iteración 4 construyó `Floor` **operativo**: `floor_plans`, `floor_zones` y `restaurant_tables` con sus
coordenadas lógicas —`x`, `y`, `width`, `height`, `rotation`, `shape`, zona—, la ocupación centralizada en
`TableOccupancy`, la unión temporal de mesas y la liberación al pagarse la cuenta.

Es decir: **el modelo de datos del piso ya está y funciona**. Lo que falta son las dos cosas que la Iteración 4
declaró fuera a propósito (§11 de su diseño):

| Fuera de la 4 | Qué falta exactamente |
|---|---|
| Editor SVG de planos | Las mesas se dan de alta por formulario y sus coordenadas quedan por omisión. Nadie las ha movido nunca |
| Tiempo real | El POS refresca por petición. El piso no se actualiza solo: hay que recargar |

Y el stack de tiempo real **no está instalado**: sin `laravel/reverb`, sin `laravel-echo`, sin
`config/broadcasting.php`, con `BROADCAST_CONNECTION=log`. Esta iteración lo instala.

---

## 1. Las cuatro preguntas que definen la iteración

### 1.1 ¿Qué es una coordenada lógica?

ADR-003 dice «coordenadas lógicas, nunca píxeles» y no dice de qué. Hay que fijarlo, porque un número sin unidad
no se puede validar ni dibujar a escala: hoy una mesa mide `80.00` y nadie sabe si es un escritorio o un salón.

**Propuesta: la unidad lógica es el CENTÍMETRO.** Una mesa de cuatro es 80×80 cm, un pasillo mide 90 cm y un salón
de 12×8 m son 1200×800. Se puede validar («una mesa de 3 cm es un error de captura»), dibujar una rejilla que
signifique algo y, más adelante, imprimir a escala.

**Y el plano necesita tamaño**, que hoy no tiene: sin `canvas_width`/`canvas_height` el `viewBox` del SVG es una
suposición, y dos clientes con suposiciones distintas dibujan el mismo plano diferente. Se añaden al plano con
omisión de 1200×800.

Los datos sembrados hoy —mesas de 80×80 separadas 120— quedan coherentes con esta lectura sin migrar nada: un
comedor de 3.6 × 2.4 m, pequeño pero real.

### 1.2 ¿Qué pasa cuando dos personas mueven el mismo plano?

Arrastrar doce mesas y guardar produce hoy doce `PATCH` independientes. Dos problemas, y el segundo es el grave:

1. Si el quinto falla, el plano queda **a medias** — mitad nuevo, mitad viejo, y nadie sabe cuál es cuál.
2. Dos gerentes editando a la vez se pisan **sin enterarse**: el último `PATCH` de cada mesa gana, y el resultado
   no es el plano de ninguno de los dos.

**Propuesta:** un solo `PUT /floor-plans/{plan}/layout` con la geometría de todas las mesas, en una transacción, y
`version` optimista en `floor_plans` — el mismo mecanismo que `pos_accounts` ya usa para que dos terminales no
cobren la misma cuenta. Un 409 con el plano actual es una respuesta útil; un plano mezclado no.

### 1.3 ¿Qué viaja por el canal, y quién puede oírlo?

Un canal de piso lo escucha **todo el que atiende**, incluidos roles que no pueden ver totales. Si el mensaje
llevara el importe de la cuenta, el permiso de ver dinero se estaría concediendo por WebSocket.

**Propuesta: el mensaje lleva el mínimo** —`{table_ulid, status, account_ulid, occurred_at}`— y quien necesite más
lo pide por la API, donde su permiso sí se comprueba. Es aburrido a propósito.

**Y la autorización del canal es exactamente el hueco que cerró D292:** un canal privado
`tenant.{t}.branch.{b}.floor` se autoriza con el ULID de sucursal que manda el **cliente**. Sin comprobar
`canOperateInBranch()`, cualquiera con sesión oye el piso de cualquier sucursal de su negocio. Es el mismo defecto
que se acaba de cerrar en once endpoints, en una superficie nueva.

### 1.4 ¿Qué pasa cuando el socket no está?

La Especificación lo exige: «Reverb … **con fallback de polling**» (§6.9). No es un adorno: en desarrollo no corre
`queue:work`, y sin worker los eventos difundidos se quedan en la cola. Sin respaldo, el piso se vería congelado y
parecería un defecto del sistema — es la trampa de D229, otra vez.

**Propuesta:** el piso siempre sabe recargarse solo. Con socket, refresca al recibir; sin socket, cada 10 segundos.
La pantalla dice cuál de los dos está usando, porque «no se actualiza» y «se actualiza cada 10 s» son dos
situaciones distintas y quien opera merece saber en cuál está.

---

## 2. Difusión: cómo se emite

### 2.1 Encolado, no inmediato

Un evento difundido puede emitirse con `ShouldBroadcast` (por cola) o `ShouldBroadcastNow` (síncrono, dentro de la
petición).

**Propuesta: por cola, en `critical`.** `Now` haría que la petición HTTP del cobro esperara a una llamada al
servidor de WebSockets, y **un Reverb caído tumbaría el cobro** — exactamente lo que D220 prohíbe: un efecto
posterior al commit no puede tumbar la operación. Con cola, un Reverb caído retrasa el pintado del piso y nada
más.

El costo es que en desarrollo, sin `queue:work`, el piso no recibe nada. Lo cubre el respaldo de polling de §1.4, y
queda documentado en `ENTORNO_LOCAL.md`.

### 2.2 Los eventos de difusión NO son los eventos de dominio

Los eventos del kernel (`PosAccountPaid`, `PosOrderCommanded`) llevan lo que los oyentes necesitan para asentar
dinero e inventario: importes, métodos, líneas. Difundirlos tal cual publicaría todo eso en un canal que oye el
piso entero.

**Propuesta:** eventos de difusión propios, en `Shared/Domain/Events/Broadcast/`, con su carga mínima. Un oyente
traduce el evento de dominio al de difusión. Es una capa más, y es la que impide que mañana alguien añada un campo
al evento de dominio y lo publique sin darse cuenta.

### 2.3 Falta un evento de dominio: el estado de una mesa

`TableOccupancy` cambia `restaurant_tables.status` —ocupa, pide cuenta, libera, marca por limpiar— **sin emitir
nada**. Hoy no importa porque nadie escucha; con piso en vivo es la fuente principal.

**Propuesta:** `TableStateChanged` en el kernel, con primitivos (D231): `{tableUlid, branchUlid, from, to,
accountUlid}`. Lo emite `TableOccupancy`, que ya es el único sitio que escribe ese campo — y lo es porque la
Iteración 4 lo centralizó ahí justo para que existiera este punto único.

---

## 3. Cambios de esquema

Cuatro columnas nuevas y ninguna tabla nueva.

### 3.1 `floor_plans`

| Columna | Tipo | Por qué |
|---|---|---|
| `canvas_width` | `DECIMAL(8,2)` NOT NULL, omisión `1200.00` | Sin tamaño de lienzo el `viewBox` es una suposición (§1.1) |
| `canvas_height` | `DECIMAL(8,2)` NOT NULL, omisión `800.00` | Ídem |
| `version` | `INT UNSIGNED` NOT NULL, omisión `1` | Bloqueo optimista del guardado por lote (§1.2) |

`CHECK` de que ambas dimensiones sean mayores que cero. Sin índice nuevo: no se consulta por ellas.

### 3.2 `restaurant_tables`

| Columna | Tipo | Por qué |
|---|---|---|
| `archived_at` | `TIMESTAMP` NULL | Retirar una mesa sin borrarla |

**Por qué una columna aparte y no el `status` que ya existe.** El enum de `status` es *qué pasa ahora en el piso*
—libre, ocupada, cuenta solicitada— y `archived_at` es *si la mesa existe siquiera*. Son ortogonales: una mesa
retirada con una cuenta abierta encima tiene que seguir viéndose hasta que se cobre, y con un solo campo
«archivada» competiría con «ocupada» y una de las dos verdades se perdería.

**Borrar no es opción:** `pos_accounts.table_id` es `RESTRICT`, y debe serlo — la cuenta de anoche dice en qué mesa
se sentó la gente, y eso no se reescribe.

Índice: el que ya existe por `(tenant_id, branch_id, status)` sigue sirviendo; el filtro de archivadas es
`archived_at IS NULL` sobre un conjunto de decenas de filas por sucursal, no miles. **Ningún índice sin
justificación escrita**, y aquí no la hay.

---

## 4. Endpoints nuevos

| Método | Ruta | Permiso | Para qué |
|---|---|---|---|
| `PUT` | `/floor-plans/{plan}/layout` | `floor.layouts.edit` | Guardado por lote con `version` (§1.2) |
| `PATCH` | `/floor-plans/{plan}` | `floor.layouts.edit` | Nombre y tamaño del lienzo |
| `POST` | `/floor-plans/{plan}/zones` | `floor.layouts.edit` | Alta de zona |
| `PATCH` | `/floor-zones/{zone}` | `floor.layouts.edit` | Nombre y orden |
| `DELETE` | `/floor-zones/{zone}` | `floor.layouts.edit` | Sólo si no tiene mesas |
| `POST` | `/restaurant-tables/{table}/archive` | `floor.layouts.edit` | Retirar |
| `POST` | `/restaurant-tables/{table}/restore` | `floor.layouts.edit` | Devolver al piso |
| `GET` | `/branches/{branch}/floor` | `floor.layouts.view` | El piso completo en una petición: plano, zonas, mesas con su estado y la cuenta que las ocupa |

El último existe para que el piso **abra con una sola petición** y para que el respaldo de polling tenga qué
llamar. Hoy pintar el piso exigiría tres llamadas y un cruce en el cliente.

**Los ocho comprueban el alcance por sucursal** con `AssertsBranchScope`, y el candado de D292 lo vigila.

Los tres permisos ya existen en el catálogo cerrado (`floor.layouts.view`, `floor.layouts.edit`,
`floor.tables.join`): **no se inventa ninguno**.

---

## 5. Canales

| Canal | Quién puede | Qué lleva |
|---|---|---|
| `private-tenant.{tenant}.branch.{branch}.floor` | Membresía activa del tenant **con alcance a esa sucursal** y permiso `floor.layouts.view` | `TableStateBroadcast`, `AccountOpenedBroadcast`, `AccountClosedBroadcast` |
| `private-tenant.{tenant}.branch.{branch}.area.{area}` | Ídem, con `printing.jobs.view` | `OrderCommandedBroadcast` — lo que la cocina tiene que preparar |

**Privados, no de presencia.** Un canal de presencia diría además quién está mirando, y nadie lo ha pedido: es
superficie de datos personales a cambio de nada.

**La autorización vive en `routes/channels.php` y usa el servicio de contexto**, no `$user->can()` — Spatie suma
roles y aquí opera el rol activo (D9). Es el mismo error que el proyecto ya tiene prohibido en HTTP, en un sitio
donde todavía no hay costumbre.

---

## 6. Pantallas

| Pantalla | Qué hace |
|---|---|
| **Editor de plano** (`/admin/piso/editor`) | SVG + Vue puro. Arrastrar, redimensionar, rotar, cambiar forma, asignar zona, archivar. Rejilla en centímetros. Guardado por lote con aviso de conflicto |
| **Piso de venta** (`/admin/pos/piso`) | **El mismo componente de render**, en sólo lectura, con el color del estado y la cuenta encima. Abrir una mesa lleva a su cuenta |
| **Comandas por área** (`/admin/pos/comandas`) | Sólo lectura, en vivo: lo comandado y todavía no marcado. Es la pantalla que hace útil difundir comandas |

**Un solo componente de render para el editor y el piso** es lo que ADR-003 pide literalmente («mismo render para
editor y piso de venta»), y la razón es que dos renders divergen: el editor mostraría una mesa donde el piso
muestra otra, y el error sería invisible hasta que alguien se sentara.

---

## 7. Los pasos

**Tanda A — el editor (sin tiempo real).** Todo verificable con recargar.

| # | Paso |
|---|---|
| 1 | Esquema: lienzo, `version`, `archived_at`. Recurso del piso completo (`GET /branches/{branch}/floor`) |
| 2 | Zonas: CRUD, orden, borrado sólo si está vacía |
| 3 | Guardado por lote con `version` y 409 útil |
| 4 | Archivar y restaurar mesas |
| 5 | El componente SVG de render, compartido |
| 6 | El editor: arrastrar, redimensionar, rotar, zona, rejilla en cm |
| 7 | El piso de venta en sólo lectura, refrescado por polling |

**Tanda B — el tiempo real.**

| # | Paso |
|---|---|
| 8 | Instalar Reverb y Echo. `config/broadcasting.php`, variables de entorno, documentación de arranque |
| 9 | `TableStateChanged` en el kernel, emitido por `TableOccupancy` |
| 10 | Eventos de difusión y sus oyentes traductores (§2.2) |
| 11 | Canales y su autorización por contexto y alcance de sucursal (§5) |
| 12 | El piso escucha; el respaldo de polling se apaga solo cuando el socket vive, y vuelve cuando muere |

**Tanda C — comandas en vivo.**

| # | Paso |
|---|---|
| 13 | Canal por área y difusión de lo comandado |
| 14 | Pantalla de comandas por área |
| 15 | Verificación en navegador de las tres pantallas, con **dos sucursales y dos pestañas** |

Quince pasos. Es cerca de la mitad de la Iteración 4, y la razón es que el modelo de datos ya estaba.

---

## 7 bis. Lo que encontró la verificación en navegador (paso 15)

Con la suite en verde, abrir las tres pantallas encontró **cuatro** defectos, y sólo uno era de esta iteración:

| Defecto | De dónde venía |
|---|---|
| Lo capturado **después** de comandar no salía nunca a la cocina: 201, línea en «Capturado» para siempre y el plato sin prepararse (D307) | Iteración 4 |
| Listar comandas respondía **500** por una relación no cargada — D265 otra vez | Iteración 4 |
| `/preparation-areas` no admitía filtro por sucursal: 422 y la lista de áreas vacía — D294 otra vez | Iteración 5 |
| `useLiveRefresh` no hacía la primera carga: diez segundos en «Cargando…», invisible en el piso porque el socket la dispara | Iteración 5 |

**Lo que sí funcionó a la primera:** el arrastre en centímetros, el guardado por lote, el 409 con las dos versiones y
las dos salidas, el piso actualizándose solo al abrir una cuenta desde otra pestaña, y la cocina recibiendo la comanda
por su canal sin tocar la pantalla.

---

## 8. Lo que se queda fuera, y por qué

| Fuera | Por qué |
|---|---|
| **Reservaciones** | D33 las deja fuera de v1 y el enum ya está previsto. Nada que hacer aquí |
| **Canales de presencia** | Saber quién mira el piso no lo ha pedido nadie |
| **Operación sin conexión** | La Especificación acepta el riesgo explícitamente: sin internet, el POS se detiene (§6.9) |
| **Bandeja de pedidos en vivo** | Es de comercio electrónico (D51), que no existe hasta la iteración 9 |
| **Escalar Reverb con Redis** | Un solo VPS (D57). Redis ni siquiera está instalado en la máquina de desarrollo, y Reverb no lo necesita para un proceso |

---

## 9. Lo que necesito de ti

**Aprobación explícita** antes de la primera migración, y en particular de estas cinco:

1. **La unidad lógica es el centímetro** y el plano gana `canvas_width`/`canvas_height` con omisión 1200×800
   (§1.1).
2. **Guardado por lote con `version`** en lugar de un `PATCH` por mesa (§1.2).
3. **La difusión va por cola** aunque eso signifique que en desarrollo el piso dependa del respaldo de polling
   mientras no corra `queue:work` (§2.1).
4. **`archived_at` como columna aparte** del `status` operativo (§3.2).
5. **Las tres pantallas**, incluida la de comandas por área — es la que hace útil la tanda C, y sin ella difundir
   comandas no le sirve a nadie.

**Y las tres decisiones que dejó abiertas la revisión de la Iteración 4** (§3.2 de `ITERACION_4_REVISION.md`), que
hay que cerrar aquí porque una de ellas es de este territorio:

| Pregunta | Nota |
|---|---|
| ¿Cobrar a crédito exige `pos.credit.charge_to_customer`? | Hoy no lo comprueba nadie. Exigirlo cambia quién puede fiar |
| ¿Se construye la **reimpresión** de comandas? | §6.9 la especifica, el permiso existe y está asignado a roles, y no hay endpoint. Es de esta familia: si entra, es un paso más en la tanda C |
| ¿`finance.cuts.close` se elimina del catálogo o se le da uso? | Sin ruta, sin código y sin rol. Parece redundante con `pos.sessions.close` |
