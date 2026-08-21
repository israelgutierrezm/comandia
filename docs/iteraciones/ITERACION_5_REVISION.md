# Iteración 5 — Revisión de cierre

**Mesas/Layout visual + tiempo real.** Quince pasos. Es la iteración más pequeña desde la 2, y a propósito: el modelo
de datos del salón lo construyó la 4, así que esto son **cuatro columnas y ninguna tabla nueva** — más el stack de
difusión, que no existía.

Este documento no repite el diseño —eso está en `ITERACION_5_DISENO.md`— ni el registro de decisiones (D296–D308).
Contesta las mismas cuatro preguntas de siempre.

---

## 1. Qué quedó construido

| Superficie | Lo que resuelve |
|---|---|
| **Editor del salón** (`/admin/piso/editor`) | SVG con Vue puro (ADR-003). Arrastrar, redimensionar, rotar, cambiar de zona, retirar y restaurar mesas. Rejilla en metros. Guardado por lote con versión |
| **Piso de venta** (`/admin/pos/piso`) | El **mismo** componente de render en sólo lectura, con el estado de cada mesa y la cuenta encima. Se actualiza solo |
| **Comandas por área** (`/admin/pos/comandas`) | La pantalla de la cocina, en vivo. Un espejo del papel, no su sustituto |
| **Tiempo real** | Reverb instalado, dos canales privados con su autorización, y respaldo de sondeo obligatorio |

**Cambios de esquema:** `floor_plans.canvas_width`, `canvas_height` y `version`; `restaurant_tables.archived_at`.

Lo que hace interesante la iteración no es el dibujo: es que **la pantalla nunca miente sobre lo que sabe**. Con socket
dice «al instante», sin socket dice «cada 10 segundos», y el respaldo existe porque la difusión va por cola y en
desarrollo no hay trabajador. Un piso congelado que se ve igual que uno al día habría sido el peor resultado posible.

---

## 2. Lo que salió mal

Menos defectos que en la 4, y de dos clases muy distintas.

### 2.1 Las pruebas de canal no probaban nada, dos veces seguidas

El hallazgo con más filo de la iteración, porque el código estaba bien y **la prueba era la que mentía**.

`phpunit.xml` fija `BROADCAST_CONNECTION=null`, y `NullBroadcaster::auth()` **no consulta los canales**: responde 200
vacío. Una prueba de «este canal se rechaza» pasa en verde con el guardián invertido, borrado o inexistente.

Y al corregirlo apareció la segunda mitad: `Broadcast::channel()` registra sobre la conexión que es la de omisión **al
arrancar**, así que cambiar el driver en la prueba deja un registro vacío — y entonces se rechaza **todo**, con lo que
las pruebas de rechazo volvían a pasar por el motivo equivocado. Hacen falta las tres cosas: cambiar el driver, purgar
la conexión y volver a cargar el archivo de canales (D302).

Es la misma familia que la primera versión del candado de purga en la Iteración 4 (D290): **una prueba que no puede
fallar**. La diferencia es que aquélla la encontré escribiéndola y ésta también — porque en los dos casos rompí el
código a propósito antes de creerme el verde.

### 2.2 Abrir una pantalla nueva encontró defectos de la iteración anterior

Cuatro defectos en la verificación del paso 15, y **dos venían de la Iteración 4**:

| Defecto | De dónde |
|---|---|
| Lo capturado **después** de comandar no salía nunca a la cocina: 201, línea en «Capturado» para siempre, plato sin prepararse (D307) | Iteración 4 |
| Listar comandas respondía **500** por una relación no cargada — D265 otra vez | Iteración 4 |
| Un filtro inventado (`branch` en `/preparation-areas`) — D294 otra vez | Iteración 5 |
| El composable no hacía la **primera carga**: diez segundos en «Cargando…» | Iteración 5 |

El primero es el más caro del proyecto en consecuencia práctica: un cliente esperando un plato que nadie está haciendo,
sin ningún error en ningún lado. Y la corrección no fue afinar la heurística sino **publicar el dato** — cada línea dice
a qué orden pertenece. Una heurística que acierta en el caso de prueba y falla en el segundo es exactamente lo que
produce este defecto.

### 2.3 Un candado nuevo, que también nació roto

`FrontendFiltersExistTest` compara cada `api.get('/ruta', {…})` del frontend contra la lista blanca **real** de su
endpoint. Existe porque los filtros inventados van **tres veces en dos iteraciones**, y las tres dejaron una pantalla
en blanco o cargando para siempre.

Su primera versión **se saltaba la primera llave de cada objeto** —el `^` de la expresión no cubría el espacio inicial—
que es justo donde suele ir el filtro que importa. Pasó en verde sobre el defecto que existe para atrapar, y lo
descubrí rompiendo el código a propósito.

Van **quince** candados estructurales. Y la regla que este proyecto ya no debería olvidar: **un candado que no se
prueba rompiéndolo no es un candado**.

---

## 3. Las dos preguntas obligatorias de cierre

### 3.1 ¿Qué endpoints no se han llamado nunca en una prueba?

Ninguno. `EveryEndpointIsExercisedTest` en verde.

### 3.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

De **131** permisos —uno menos que en la 4, porque `finance.cuts.close` se eliminó (D298)—, **98** tienen ruta y **33**
no. De esos 33, dieciséis son de módulos construidos, y esta vez el reparto es limpio:

**Ocho se comprueban DENTRO del código**, que es lo correcto porque su condición sólo se conoce con los datos en la
mano: los tres umbrales de autorización, los tres de descuentos, `identity.employee_profiles.view_sensitive` y
—desde esta iteración— `pos.credit.charge_to_customer`.

**Ocho no se comprueban en ningún lado, y los ocho son de superficies que no existen:** `reporting.*` (iteración 8),
`customers.addresses.manage` y `customers.fiscal_profiles.manage` (iteración 7), `audit.entries.export` (iteración 11),
y `tenancy.modules.view` / `tenancy.subscription.view` — el módulo `Tenancy` **no tiene ni un controlador**.

Es decir: **no queda ningún permiso que mienta**. Los tres de la Iteración 4 se cerraron (D296, D298, D304), y lo que
resta está declarado a propósito para iteraciones futuras.

---

## 4. Lo que se lleva la Iteración 6

### 4.1 Decisiones pendientes del dueño del producto

| Pregunta | Origen |
|---|---|
| ¿Un listado filtrado por una sucursal fuera de alcance responde 403 o devuelve vacío? | D292, sigue abierta |
| ¿La pantalla de comandas necesita marcar «preparado»? Hoy se limita a la jornada, que es el único corte honesto que existe | D308 |
| ¿`Mesero con cobro` debería poder fiar? Hoy no puede, y es lo que las plantillas ya decían | D305 |
| D219, D221, D230 | Abiertas desde la Iteración 3 |

### 4.2 Deuda técnica declarada

- Las **pantallas anteriores al paso 20 de la Iteración 4** siguen pintando fechas en la hora del navegador. El
  ayudante existe; falta retrofitearlas (D293).
- `ArticleBranchOverrideController::assertBranchInScope()` sigue siendo un método estático de un controlador al que
  llama `Costing`. El guardián del kernel ya existe (D292).
- `finance.cuts.close` **sigue en la base** de las instalaciones existentes: `comandia:permissions:sync` no borra
  permisos retirados, a propósito.
- El candado de filtros **no ve** las llamadas con ruta o parámetros dinámicos. Cubre la forma en que están escritas
  casi todas, y ampliarlo a expresiones construidas produciría fallos falsos.

### 4.3 Deuda de entorno

`table_definition_cache` sigue en **600**. Y ahora hay dos procesos más que recordar en desarrollo —`reverb:start` y
`queue:work`—, documentados en `ENTORNO_LOCAL.md` §9 junto con la razón de que sin ellos el piso no se mueva solo.

### 4.4 Lo que la Iteración 6 puede dar por hecho

El salón se dibuja y se opera: un negocio puede montar su plano, moverlo sin pisarse con otro gerente, ver el piso en
vivo y darle a la cocina una pantalla. El tiempo real existe, con dos canales autorizados como endpoints y un respaldo
que hace que ninguna pantalla dependa de él.

### 4.5 La lección de esta iteración

**Una prueba que no puede fallar es peor que no tener prueba**, porque ocupa su lugar. Esta iteración encontró dos
—las de canal y el candado nuevo— y las dos las descubrí por el mismo método: romper el código a propósito y comprobar
que la prueba se entera. Es barato, tarda un minuto, y es lo único que distingue una red de seguridad de un adorno.
