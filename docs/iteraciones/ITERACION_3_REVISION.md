# Iteración 3 — Revisión de cierre

**Inventarios + Compras.** Once pasos en doce commits. La suite pasó de **566 a 849** pruebas.

Este documento no repite el diseño —eso está en `ITERACION_3_DISENO.md`— ni el registro de decisiones
(D152–D230). Contesta cuatro preguntas: **qué quedó construido**, **qué salió mal y qué se aprendió**, las
**dos preguntas obligatorias de cierre** (§14 de la Arquitectura Maestra), y **qué se lleva la Iteración 4**.

---

## 1. Qué quedó construido

| Módulo | Tablas | Lo que resuelve |
|---|---|---|
| `Inventory` | 10 | Kardex inmutable con saldo por movimiento, existencias como proyección, lotes y caducidades con FEFO, entradas/salidas/ajustes, mermas con motivo y umbral de autorización, conteos físicos ciegos, transferencias de cinco pasos con almacén de tránsito, órdenes de producción con receta congelada |
| `Purchasing` | 4 | Proveedores, historial de precios por proveedor comparable por unidad base, recepciones de compra que mueven inventario y capturan costo |

Catorce tablas, todas con `tenant_id` NOT NULL y global scope. Dos append-only —el kardex y el historial de
precios de proveedor— verificadas por el candado de inmutables, que **pidió el kardex por su cuenta**: la
prueba falló al aparecer un modelo con el trait fuera de la lista declarada.

Lo que hace interesante la iteración no son las tablas: es que **el inventario no bloquea a nadie y aun así
cuadra**. El kardex es la única puerta de escritura y calcula su saldo bajo lock; las existencias son una
proyección reconstruible; una salida se parte por FEFO entre lotes; la mercancía en viaje vive en un almacén
de tránsito en lugar de desaparecer entre dos sucursales; y un conteo físico se captura a ciegas para que la
diferencia signifique algo.

---

## 2. Lo que salió mal

Alrededor de **treinta** defectos, casi todos con la suite en verde. Vale más clasificarlos que enumerarlos,
porque el patrón se repite y se puede atacar.

### 2.1 Lo que la suite no podía ver, y ahora se mide

**Diez defectos de interfaz**, encontrados abriendo el navegador con 842 y luego 847 pruebas en verde. Uno de
ellos le decía a quien confirmaba una recepción que su compra no se había registrado **cuando sí** (D220), lo
que invita a repetir la operación. Otro cambiaba el significado del campo de precio bajo las manos de quien
escribía, y capturó 60 000 000 g donde había 5000.

La lección estaba escrita antes de empezar y ahora tiene número: **la suite en verde no es evidencia sobre el
frontend**. Es regla en §11 de la Arquitectura Maestra: ninguna pantalla se da por hecha sin abrirla.

### 2.2 Defectos que vivían en el hueco entre versiones

**Un permiso agregado después del alta no existía** para los negocios ya creados (D219), así que su ruta
devolvía 403 para todo el mundo, para siempre. La suite no puede verlo: cada prueba provisiona un negocio
nuevo con el catálogo del día. El defecto vive exactamente donde ninguna prueba mira — entre «se dio de alta
con la versión vieja» y «se actualizó el código», que es el estado normal de cualquier instalación real. De
ahí salió `comandia:permissions:sync`.

De la misma familia: `comandia:demo:seed --fresh` dejó de poder purgar porque la lista de tablas no conocía
las once nuevas (D220), y el negocio de demostración **no sembraba ningún PIN**, lo que convertía el diálogo
de autorización en un callejón sin salida (D224).

### 2.3 Cinco candados nuevos, y uno que se quitó

| Candado | El defecto que cierra |
|---|---|
| Nombres de ayudantes únicos (D191) | Dos archivos de Pest con el mismo ayudante **abortan la suite completa** con `Cannot redeclare`, y correr el archivo solo pasa en verde |
| Oyentes registrados / eventos despachados (D216) | Un oyente sin registrar **no falla, no corre**: el efecto simplemente no ocurre y en producción no hay error |
| Servicios devuelven modelos releídos (D217) | Eloquent devuelve el atributo asignado y no el almacenado: un `DECIMAL(12,4)` vuelve sin decimales y una **columna generada** no vuelve en absoluto. Encontró **diez** servicios |
| Garantías estructurales sin la aplicación (D218) | Un `CHECK` o un `unique` pueden estar en el diseño y no en la base; probarlos por la API sólo prueba la validación |
| Purga del sembrador de demostración (D220) | La lista escrita a mano se rompe con cada iteración, y el fallo aparece preparando una demo comercial |

Y uno **escrito y retirado el mismo día**: exigía que toda tabla acotada estuviera en la lista de purga, y
encontró diez «faltantes» que no faltaban porque su FK es `CASCADE`. Un candado que pide trabajo inútil se
acaba apagando, y cuando alguien lo apaga se lleva por delante al que sí servía.

Además se **corrigió** un candado propio: el de refs sin `.value` marcaba una lectura correcta porque su
premisa había caducado (D227).

### 2.4 Los errores de método, que son los que más valen

Tres, y conviene dejarlos escritos:

1. **Marqué una prueba `->throws()` para que pasara.** Era la señal de que el código estaba mal: editar un
   motivo de sistema devolvía 500 donde correspondía un 422 (D186). Y `->throws()` a nivel de prueba esconde
   todas las aserciones anteriores.
2. **Llamé defecto a una decisión deliberada de la Iteración 2** (D212). La suite falló con una prueba que
   esperaba el lanzamiento **a propósito**, con su razonamiento escrito. Reescribí la decisión como
   reemplazo explícito en lugar de cambiarla en silencio.
3. **Atribuí un síntoma no verificado a un hueco real** (D182): afirmé que dos listados «abrían en la entrada
   más vieja» sin comprobarlo, cuando su propio `reorder()` ya lo resolvía.

Y una vez **una prueba me corrigió**: aserté que la masa costaba 50.00, mi captura manual, y costaba 6.00
porque es producible y su costo se deriva de la receta (D16). La aserción estaba mal, no el código.

---

## 3. Las dos preguntas obligatorias de cierre

### 3.1 ¿Qué endpoints no se han llamado nunca en una prueba?

Ninguno. Es candado desde la Iteración 2 (`EveryEndpointIsExercisedTest`, D146) y está verde.

### 3.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

Seis. **Cuatro son correctos por diseño** y conviene decir por qué, porque la respuesta «tiene ruta o está
mal» es demasiado gruesa:

| Permiso | Por qué no tiene ruta |
|---|---|
| `inventory.waste.authorize_above_threshold` | Se consume por `POST /authorizations` (ADR-008). No protege una ruta: **es** la autorización |
| `inventory.counts.authorize_above_threshold` | Igual |
| `identity.employee_profiles.view_sensitive` | Permiso de **grado**: el controlador lo consulta para decidir si expone PII y auditar la lectura, sobre una ruta que ya tiene su propio permiso |
| `audit.entries.export` | La exportación llega con el motor de reportes y su cola (Iteración 8) |

**Dos son pendientes reales**, los dos de bajo riesgo y con tres iteraciones de antigüedad:
`tenancy.modules.view` y `tenancy.subscription.view`. Un tenant no puede consultar por API su suscripción ni
sus módulos contratados; el frontend recibe los módulos activos por Inertia, así que nada está roto — falta la
lectura formal. Se resuelve con el panel de super admin (D6) o antes, si alguna pantalla la necesita.

**Propuesta de método para la Iteración 4.** Esta pregunta es manual porque el catálogo declara el nombre del
permiso y nada más. Si cada permiso declarara **cómo se consume** —`route`, `grade`, `pin`, `pending`— la
pregunta se vuelve candado con excepciones declaradas y justificadas, que es el patrón que el proyecto ya usa
para `withoutGlobalScopes`. Cuesta poco y convierte una revisión que se puede olvidar en una prueba que no.

---

## 4. Lo que se lleva la Iteración 4

### 4.1 Preguntas abiertas del dueño del producto

| # | Pregunta | Riesgo si se deja correr |
|---|---|---|
| D219 | ¿Un permiso nuevo debe llegar a los roles editables que el negocio nunca tocó? | Cada iteración que agregue permisos deja a gerente, cajero y mesero sin ellos aunque `RoleTemplates` diga que les corresponden |
| D221 | ¿Una compra debe ganarle **siempre** a una captura manual de costo, sin importar el orden temporal? | Hoy manda el más reciente. Afecta a la valuación y a los precios sugeridos |
| D228 | ¿El rol activo debe persistir? | Quien baja a un rol menor cree operar con él y opera con el mayor. **Afecta al POS**: el PIN y el rol activo son el corazón de §6.3 |
| D230 | ¿Se puede producir un artículo no inventariable? | Entradas de kardex de artículos que nadie inventaría |

D228 es la que más urge, y no por elegancia: la Iteración 4 construye las acciones sensibles del POS
—descuentos, cancelación post-comanda, abrir cajón— y todas dependen de saber con qué rol opera una persona.

### 4.2 Deuda técnica declarada

- **Sin órdenes de compra formales** (D26): no se puede comparar lo pedido con lo recibido. Evolución prevista.
- **Sin devoluciones a proveedor por documento**: el `kind` `purchase_return` existe y se registra a mano.
- **Una sola moneda comparable**: los precios de proveedor agrupan por moneda porque no hay tipo de cambio.
- **Tasa de IVA por negocio y no por artículo** (D150). **Vence antes de la Iteración 4 en la práctica**, no
  antes de la 7 como decía: el POS emite el primer documento con desglose de IVA. Ver §4.3.

### 4.3 Dos avisos para el diseño de la Iteración 4

**El IVA.** D150 dejó la tasa por negocio con override por sucursal, y fijó tres pasos si aparece un cliente
de tasas mixtas. El paso 2 —**congelar la tasa en la línea del documento al emitirlo**— es obligación de la
Iteración 4, porque es la que escribe la primera línea de venta. Congelarla ahora hace que agregar
`articles.vat_rate` después cueste una columna; no congelarla hace que cueste recalcular documentos emitidos,
que es justo lo que D150 dice que no se puede hacer.

**La cola.** En desarrollo no corre `queue:work`, así que ningún efecto asíncrono ocurre (D229). El POS
depende de esto más que cualquier módulo anterior: §6.2 exige que **el descuento de inventario por venta sea
asíncrono y no bloquee la venta**. Sin worker, vender no descontará nada y parecerá un defecto del sistema.

### 4.4 Lo que la Iteración 4 puede dar por hecho

- **Kardex con una sola puerta de escritura**, idempotente por llave y con saldo bajo lock. El consumo por
  venta es un `kind` ya declarado (`sale_consumption`) esperando su oyente.
- **Explosión de recetas con rendimiento y unidades** (`ResolveProductionConsumption`), que es exactamente lo
  que hace falta para descontar los insumos de un platillo vendido.
- **Áreas de preparación con su almacén configurado**, que es de dónde sale el consumo de lo que preparan.
- **Foliación sin huecos** por (tenant, sucursal, tipo, serie) bajo lock, ya usada por dos documentos.
- **Autorización por PIN** con un contrato único: 409 `authorization_required` con el permiso que hace falta,
  y un diálogo de frontend que lo consume sin saber de qué operación venía.
