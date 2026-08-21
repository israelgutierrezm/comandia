# Iteración 4 — Revisión de cierre

**El punto de venta, completo y operable.** Veinte pasos en veintitrés commits. La suite pasó de **849 a 1 157**
pruebas (1 149 en paralelo + 8 en serie).

Este documento no repite el diseño —eso está en `ITERACION_4_DISENO.md`— ni el registro de decisiones (D231–D295).
Contesta cuatro preguntas: **qué quedó construido**, **qué salió mal y qué se aprendió**, las **dos preguntas
obligatorias de cierre** (§14 de la Arquitectura Maestra), y **qué se lleva la Iteración 5**.

---

## 1. Qué quedó construido

| Módulo | Tablas nuevas | Lo que resuelve |
|---|---|---|
| `Pos` | 11 | Cuentas (mesa, barra, para llevar), órdenes y comandas por área, descuentos con PIN, cobro multi-método con propina y cambio, dividir/mover/juntar, sesiones de caja con declaración, retiro y cierre |
| `Finance` | 6 | Diario financiero como única verdad del dinero, gastos con umbral de autorización, depósitos bancarios, liquidación de propinas, corte calculado |
| `Customers` | 3 | Clientes, crédito con límite y saldo como proyección, abonos |
| `Floor` | 2 | Planos de piso con zonas, mesas en operación con ocupación y unión |
| `Printing` | 2 | Trabajos de impresión con reintento y agentes con token propio |
| `Organization` | 2 | Terminales y áreas de preparación al servicio del POS |

**Veintisiete tablas**, todas con `tenant_id` NOT NULL y global scope. Cuatro pantallas nuevas: caja, piso de cuentas,
la cuenta y el ruteo de áreas.

Lo que hace interesante la iteración no son las tablas: es que **el POS no se bloquea por nada y aun así el dinero
cuadra**. El inventario se descuenta por cola y jamás detiene un cobro; el corte se calcula del diario y no se almacena
como verdad paralela; la orden, la cuenta y la comanda son tres cosas distintas con tres ciclos de vida; y los efectos
entre módulos viajan por eventos, sin que el POS escriba nunca en finanzas ni en inventarios.

---

## 2. Lo que salió mal

Alrededor de **cuarenta** defectos, casi todos con la suite en verde. El patrón se repite lo bastante como para
clasificarlo.

### 2.1 El defecto que hacía ver corta toda caja con cambio

Es el más caro de la iteración y el que mejor resume el resto. El cobro se asentaba en el diario por el **importe de la
cuenta** mientras el cambio se asentaba **entero**: la entrada iba neta y la salida bruta, así que el cambio se
descontaba dos veces. Un cobro de 196 con 300 entregados y 20 de propina dejaba el corte en **932** con **1 016** en el
cajón — un faltante exacto al cambio dado, en la caja de alguien a quien se le achaca (D295).

**Ninguna prueba podía verlo, y una lo enterraba.** Las dos del corte pagan exacto (`tendered = total + propina`), y con
cambio cero el asiento del cambio ni siquiera se crea. Y una tercera fijaba `payment = 850`, que era precisamente el
valor equivocado. El defecto vivía en el caso que nadie escribe por costumbre.

Lo encontró **operar el navegador y contar el cajón con la mano**. La prueba nueva no comprueba montos sueltos: fija la
invariante que faltaba —*la suma de lo que mueve el cajón es lo que hay en el cajón*—, que es la que no se puede
satisfacer por accidente.

### 2.2 Once endpoints sin comprobar el alcance por sucursal

Un turno de caja se podía abrir en **otra sucursal**: el desplegable ofrecía las dos terminales llamadas «Caja 1» —el
nombre es único por sucursal, no por negocio— y la ajena devolvía **201** (D292).

**El `tenant_id` no protege de esto.** La sucursal ajena es del mismo negocio: pasa el global scope, pasa el `exists` de
la validación y llega al controlador como un modelo perfectamente válido. Es la lección estructural de la iteración —
el aislamiento que más se vigila en este proyecto no es el único que hay.

**El mecanismo existía y no se usaba.** `ResolveTenantContext` hace las tres comprobaciones correctas, pero sobre la
**cabecera**; los endpoints que reciben la sucursal en el **cuerpo** no pasaban por ahí. Y
`Authorize::authorize($permiso, ?int $branchId = null)` acepta la sucursal como argumento **opcional**: ese `= null` es
la forma que toma el olvido, porque una llamada que lo omite no falla, autoriza.

Había además **tres copias** de la misma comprobación, una de ellas un método estático de un controlador de `Catalog` al
que llamaba `Costing` —una dependencia entre módulos por la puerta de atrás—. Ahora hay un guardián en el kernel y un
candado.

### 2.3 Doce defectos de interfaz, todos invisibles con una sola sucursal

Encontrados abriendo el navegador con 1 148 pruebas en verde. Las mesas salían duplicadas —«M1, M1, M2, M2…»— porque el
código es único por sucursal; la caja mostraba el turno de la **otra** sucursal; el cambio a devolver no se pintaba
aunque el servidor lo mandaba; y un filtro inventado (`is_sellable` en lugar de `available_in_pos`) dejaba la pantalla
de la cuenta **completamente en blanco**, porque el 422 del catálogo tumbaba el `Promise.all` entero (D293, D294).

Y uno más fino: `branch_timezone` viaja al cliente **desde la Iteración 1**, con un comentario que dice para qué es, y
hasta el paso 20 **ninguna pantalla lo consumía**. Todas las horas se pintaban en la zona del navegador. Un dato servido
y no consumido se ve exactamente igual que si funcionara, hasta el día que dos zonas horarias no coinciden — y en un
corte la hora decide a qué jornada pertenece el dinero.

### 2.4 Los errores de método, que son los que más valen

**Una advertencia escrita no impide repetir el fallo.** Dos veces un encabezado que yo mismo había escrito advertía
exactamente del error que cometí a continuación: el signo del cambio en el diario (D253) y la guarda de transacción
escrita donde no podía fallar (D270). Sólo lo impide una prueba que falla sola.

**Cuatro candados estaban sutilmente mal**, y uno de ellos pasaba en verde sobre una lista vacía. La primera versión del
candado de purga daba por buena la cascada de `tenants` y era **circular**: ese `DELETE` final sólo funciona porque la
lista ya vació todo antes. La primera versión del candado de alcance no encontraba el caso que lo motivó, porque la caja
resuelve una `Terminal` y no una `Branch` (D290, D292). Un candado que no se prueba rompiéndolo no es un candado.

**Cuatro candados nuevos**, para catorce en total: redondeo de dinero, eventos entre módulos, cobertura de la purga del
demo y alcance por sucursal.

---

## 3. Las dos preguntas obligatorias de cierre

### 3.1 ¿Qué endpoints no se han llamado nunca en una prueba?

Ninguno. `EveryEndpointIsExercisedTest` está en verde y ya no es revisión manual.

### 3.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

De **132** permisos, **97** tienen ruta y **35** no. La mayoría son de módulos que aún no existen —comercio
electrónico, menús digitales, tableros, promociones, notificaciones— y están declarados a propósito.

De los que sí tienen módulo construido, la mayoría se comprueban **dentro** del código y no en la ruta, que es lo
correcto: `finance.expenses.authorize_above_threshold`, `inventory.waste.authorize_above_threshold`,
`inventory.counts.authorize_above_threshold` y los tres de descuentos viven en excepciones de autorización con PIN,
porque el umbral sólo se conoce con el monto en la mano.

**Pero tres no los comprueba nadie**, y eso es peor que faltar: un permiso que se puede otorgar y no hace nada **parece
protección**.

| Permiso | Estado |
|---|---|
| `pos.credit.charge_to_customer` | Declarado y **asignado en `RoleTemplates`**, nunca comprobado. Cobrar a crédito exige hoy sólo poder cobrar; lo único que pide PIN es **rebasar el límite**, y eso usa otro permiso (`finance.customer_credit.manage`) |
| `printing.jobs.reprint` | Declarado y asignado, sin que lo comprobara nadie. **Corrección (Iteración 5, D304): el endpoint SÍ existía** —`POST /pos-tickets/{ticket}/reprint`, desde el paso 9— pero pedía el permiso de *comandar*. Lo que faltaba no era la reimpresión sino que usara su permiso |
| `finance.cuts.close` | Declarado, sin ruta, sin código y sin asignar a ningún rol. Parece redundante con `pos.sessions.close` |

**No los toqué.** Hacer que `pos.credit.charge_to_customer` empiece a exigirse cambia quién puede cobrar a crédito, y
eso es una regla de negocio, no una corrección. Va a decisión en la apertura de la Iteración 5.

---

## 4. Lo que se lleva la Iteración 5

### 4.1 Decisiones pendientes del dueño del producto

| Pregunta | Origen |
|---|---|
| ¿Cobrar a crédito exige `pos.credit.charge_to_customer`? | §3.2 de este documento |
| ¿Hace falta reimprimir una comanda a voluntad, o basta el reintento? | §3.2 |
| ¿`finance.cuts.close` se elimina del catálogo o se le da uso? | §3.2 |
| ¿Un listado filtrado por una sucursal fuera de alcance responde 403 o devuelve vacío? | D292 |
| D219, D221, D230 | Abiertas desde la Iteración 3 |

### 4.2 Deuda técnica declarada

- Las **pantallas anteriores al paso 20** pintan las fechas en la hora del navegador. Existe
  `resources/js/support/datetime.js`; falta retrofitearlas (D293).
- `ArticleBranchOverrideController::assertBranchInScope()` sigue siendo un método **estático de un controlador** al que
  llama `Costing`. El guardián del kernel ya existe; migrarlo es limpieza, no seguridad (D292).
- En el negocio de demostración quedan **84 pesos** de diferencia de un cobro anterior al arreglo del corte. Es el
  comportamiento correcto —el diario es inmutable y se enmienda por reversa— y se limpian volviendo a sembrar.

### 4.3 Deuda de entorno, que ya no es menor

`table_definition_cache` de MySQL sigue en **600** con **927 tablas** en el esquema de pruebas. El `SQLSTATE 1615` ya no
es un tropiezo ocasional: apareció en cinco corridas de esta iteración y obliga a un `FLUSH TABLES` antes de la suite
serial. El arreglo está documentado en `ENTORNO_LOCAL.md` §8 y es un cambio en `my.ini` de la máquina.

### 4.4 Lo que la Iteración 5 puede dar por hecho

Un negocio puede **abrir caja, atender mesas, comandar por área, cobrar de todas las formas previstas, fiar, dar
propinas, registrar gastos, depositar y cuadrar el dinero al cerrar**. El diario financiero es la única verdad del
dinero y el corte se calcula de él. Los efectos entre módulos viajan por eventos idempotentes, y hay catorce candados
estructurales vigilando que siga siendo así.

### 4.5 Y la regla que esta iteración volvió a pagar

**La suite en verde no es evidencia sobre el frontend, ni sobre el dinero.** Los dos defectos más caros —once endpoints
sin alcance y toda caja corta por el importe del cambio— estaban bajo 1 148 pruebas en verde, y los encontró abrir el
navegador y contar el cajón con la mano. Es §11 de la Arquitectura Maestra, y la Iteración 4 es la segunda que la cobra.
