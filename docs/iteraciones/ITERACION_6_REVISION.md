# Iteración 6 — Revisión de cierre

**Promociones + Clientes/CFDI-ready.** Dos módulos que no se tocan entre sí y que, sin embargo, definen la iteración: uno
enseña cómo el POS usa algo que no conoce; el otro, cómo un ticket se prepara para facturar sin timbrar todavía.

Este documento no repite el diseño —eso está en `ITERACION_6_DISENO.md`— ni el registro de decisiones (D309–D317).
Contesta las mismas cuatro preguntas de siempre.

---

## 1. Qué quedó construido

| Superficie | Lo que resuelve |
|---|---|
| **Promociones** (`/admin/promociones`) | Alta y edición de promociones: porcentaje, monto, NxM y precio especial, sobre artículos o categorías, con ventana de vigencia (fechas, horario, días de la semana) y sucursales. Edición **en el sitio con versión** y candado optimista |
| **Motor de promociones** (sin pantalla) | Vive en su módulo. El POS lo consulta por una **sonda del kernel** (`PromotionResolver`, D310) y nunca lo nombra. Sin el módulo, responde la `NullPromotionResolver`: el POS jamás se bloquea |
| **Vista previa de promociones** (en la cuenta) | Un GET puro corre el resolver sobre las líneas y muestra «se aplica al cobrar: −$X», sin escribir. Lo que queda grabado lo decide el cobro, una sola vez (D311, D318) |
| **Clientes** (`/admin/clientes`) | Lista con alta express —sólo el nombre (D43)— y filtro de deudores |
| **Ficha del cliente** (`/admin/clientes/{ulid}`) | El expediente: datos, crédito (lectura), **perfiles fiscales**, **direcciones** —ambos con su predeterminado— y el **historial de consumos** |
| **Foto fiscal en el ticket** | Al cobrar, el ticket **congela** RFC, razón social, CP, régimen y uso. CFDI-ready sin timbrado (D317, ADR-005) |
| **Catálogo del SAT** | Regímenes y usos CFDI **en código**, no en tabla: son globales, no de tenant |

**Tablas nuevas:** `promotions`, `promotion_targets`, `promotion_branches`, `promotion_applications`,
`customer_fiscal_profiles`, `customer_addresses`. **Columnas nuevas:** `pos_discounts.source` y `.promotion_ulid`; cinco
columnas fiscales en `pos_tickets`; `customers.birthday`; y `promotion` sumado al enum de `financial_movements.type`.

Lo que hace interesante la iteración no son las promociones ni la factura por separado: son **maneras de cruzar un
límite sin romperlo**. El POS aplica un descuento que no sabe calcular —le pregunta al kernel y el kernel responde, o
responde «ninguno»— y lo escribe en `pos_discounts`, que es **inmutable**. Por eso las promociones no se re-evalúan en
vivo: la sonda es pura durante la captura y sólo **al cobrar** se materializan una vez (D311). El ticket no guarda una
referencia al perfil fiscal del cliente —que podría cambiar— sino una **copia congelada**: la factura de ayer no se
altera porque hoy el cliente corrigió su RFC. Y el expediente pinta los consumos del cliente sin que `Customers` toque
una sola tabla del POS: **le pregunta** por una sonda del kernel, la misma inversión que ya usaban el salón y finanzas
para preguntarle al POS (D318). Tres cruces, tres módulos que siguen sin nombrarse entre sí.

---

## 2. Lo que salió mal

Pocos defectos, y casi todos de una misma familia: **el código y el esquema decían cosas distintas, y nadie se enteraba
hasta que un valor real cruzaba la frontera.**

### 2.1 El enum que existía en PHP pero no en la base

`FinancialMovementType::Promotion` se agregó como caso de PHP, con su etiqueta y su signo, y las pruebas de dominio
pasaron: para PHP el enum estaba completo. Pero `financial_movements.type` es un `ENUM` de MySQL con su propia lista, y
esa lista no incluía `promotion`. El resultado no fue un error al arrancar ni una prueba en rojo: fue un **500 «Data
truncated for column 'type'»** la primera vez que un movimiento de promoción intentó asentarse en el diario —es decir, al
cobrar de verdad—. Un enum de aplicación y un enum de columna son **dos listas que deben coincidir**, y el ORM esconde
que son dos hasta que un valor que está en una y no en la otra llega al `INSERT`. Se cerró con una migración que altera
la columna.

### 2.2 La columna generada que MySQL no dejó anclar, y la migración que quedó a medias

El «uno predeterminado» de perfiles fiscales y direcciones se garantiza con una columna generada + índice único (D78,
D316). La primera versión la hizo `STORED`, y MySQL la rechazó con el **error 1215**: una columna generada `STORED` no
puede referenciar una columna que además tiene `ON DELETE CASCADE`. La corrección fue `VIRTUAL`, que sí lo permite. Pero
el hallazgo con más filo no fue el 1215 sino lo que dejó detrás: **el DDL de MySQL no es transaccional**. La migración
agregaba `birthday` a `customers` *antes* de crear las tablas que fallaban, así que cada intento fallido dejaba mitad del
esquema aplicado —la columna sí, las tablas no— y hubo que limpiarlo a mano entre intentos. Una migración que crea varias
cosas no es atómica: si falla en la tercera, las dos primeras quedaron.

### 2.3 Una auditoría que mentía sobre lo que había pasado

`CustomerController::update()` registraba en la bitácora `CUSTOMER_CREATED` — el mismo evento que el alta. Es un defecto
**anterior a esta iteración**: el método existía desde que se construyó Clientes, y editar un cliente quedaba archivado
como si se hubiera creado. Lo encontró la **revisión adversaria** al pasar por el módulo, no una prueba. Una bitácora que
nombra mal lo que ocurrió es peor que no tenerla, porque se le cree. Se agregó `CUSTOMER_UPDATED` y se usó.

### 2.4 El código '626' que dejó de ser texto

Una prueba del catálogo del SAT afirmaba `toContain('626')` sobre la respuesta JSON. Falló, y el motivo es una trampa de
PHP: una clave de arreglo que **parece** un entero se coacciona a entero, así que `'626' => …` se vuelve `626 => …`, y el
JSON lo emite como número, no como cadena. El catálogo se corrigió para emitir los códigos como texto (`(string) $code`),
que es lo que un código de catálogo es: una etiqueta, no una cantidad.

### 2.5 Los candados hicieron su trabajo

Dos candados estructurales atraparon lo que existen para atrapar, sin que yo tuviera que acordarme: `DemoPurgeCovers`
exigió sumar las tres tablas de promociones a la purga del tenant demo, e `ImmutableTables` exigió declarar
`promotion_applications` como inmutable. Ninguno es un hallazgo dramático; los dos son la prueba de que la red de
seguridad de las iteraciones anteriores sigue tensa.

### 2.6 Tres entregables comprometidos que casi se cierran sin construir

El hallazgo más incómodo no fue un defecto de código sino de alcance. Al ir a escribir esta revisión, comparé el diseño
aprobado —sus trece pasos— contra lo construido, y faltaban **tres cosas que el diseño sí comprometía**: el endpoint de
consumos del cliente (paso 9), su historial en el expediente (paso 12) y el precio promocional previsualizado en la
cuenta (paso 11). Estaban a punto de quedar reclasificadas como «deuda futura» en este mismo documento — que es la forma
educada de no haberlas hecho.

Se construyeron (D318), y una de ellas traía además una decisión que el diseño no había resuelto: el paso 9 esbozaba un
`GET /customers/{customer}/history` **servido por `Customers`**, que habría leído `pos_accounts` y violado ADR-002, porque
`Pos` ya depende de `Customers` y la consulta al revés cierra un ciclo. La versión correcta es la sonda del kernel. La
lección de método: comparar «lo que el diseño prometió» contra «lo que corre» es un paso del cierre, no una cortesía —
sin él, el diseño aprobado y la entrega se separan en silencio, y el documento de cierre lo tapa.

### 2.7 La verificación en navegador encontró cuatro defectos que la suite en verde no vio

La suite —1206 pruebas— pasaba en verde, y aun así **cuatro defectos** salieron al abrir las pantallas en el navegador.
Es, otra vez, la lección de las iteraciones anteriores: el verde de la suite no es la entrega.

| Defecto | Por qué la suite no lo vio |
|---|---|
| **Crear una promoción para «todas las sucursales» respondía 422.** La regla exigía `min:1` en `branch_ulids` aunque `all_branches` fuera verdadero; la UI manda `branch_ulids: []`. | La prueba de creación **omitía** el campo (ausente), no lo mandaba vacío. `required_if` no aplica a un ausente, pero `min:1` sí a un `[]`. Se cerró con `exclude_if` y una prueba que manda el arreglo vacío |
| **Editar una promoción respondía 422.** El `PromotionResource` devolvía los objetivos por **id interno** (`article_category_id`), que además viola «nunca exponer ids secuenciales» (D3); la pantalla no podía remapearlos y reenviaba `targets: []`. | Ninguna prueba hacía el ciclo real de edición —leer lo que el API da y mandarlo de vuelta—. Se agregó esa prueba, y el resource ahora devuelve ULIDs |
| **La lista de clientes salía en blanco.** Usaba `list.filters.value`, pero `filters` es `reactive`, no un `ref`: `.value` es `undefined`. | La suite no ejercita plantillas de Vue. El candado de `.value` mira otro patrón (refs que faltan, no un `.value` de más sobre un reactive) |
| **La lista de clientes nunca cargaba:** faltaba `onMounted(list.load())`. | Igual: es comportamiento de montaje del componente, invisible para una prueba de API |

Los dos de promociones se corrigieron en el backend (regla y resource) con pruebas nuevas que reproducen el fallo; los
dos de clientes eran de frontend y se confirmaron en el navegador. La vista previa de promoción, en cambio, funcionó a la
primera: capturar una bebida pinta «10% en Bebidas −$9.30» y el total de arriba no lo incluye hasta cobrar.

---

## 3. Las dos preguntas obligatorias de cierre

### 3.1 ¿Qué endpoints no se han llamado nunca en una prueba?

Ninguno. `EveryEndpointIsExercisedTest` en verde, dentro de las 1201 pruebas en paralelo (5239 aserciones) más 8 del
grupo serial.

### 3.2 ¿Qué permisos de los módulos ya construidos no tienen ruta?

De **131** permisos —el mismo total que en la 5, porque el catálogo está cerrado desde D10 y las promociones y el
expediente ya estaban declarados—, **102** tienen ruta y **29** no. La cuenta mejoró en cuatro respecto a la 5, y son
justo los que aquella revisión marcó como pendientes: `customers.fiscal_profiles.manage`, `customers.addresses.manage`,
`promotions.promotions.view` y `promotions.promotions.manage` **ya tienen ruta**.

De los **29** sin ruta:

**Ocho se comprueban DENTRO del código**, que es lo correcto porque su condición sólo se conoce con los datos en la mano:
los tres umbrales de autorización (`finance.expenses`, `inventory.counts`, `inventory.waste`), los tres de descuentos
(`pos.discounts.apply_account/apply_item/courtesy`), `identity.employee_profiles.view_sensitive` y
`pos.credit.charge_to_customer`.

**Veintiuno son superficies que aún no existen**, declaradas a propósito: `dashboards.*` y `reporting.*` (Iteración 7),
`ecommerce.*`, `digital_menus.*` y `promotions.coupons.manage` (Iteración 8), `audit.entries.export` (Iteración 11),
`notifications.preferences.manage`, y `tenancy.modules.view` / `tenancy.subscription.view` —el módulo `Tenancy` sigue sin
un solo controlador—.

La **única ausencia nueva** que introduce esta iteración es `promotions.coupons.manage`, y es deliberada: los cupones se
difirieron a la Iteración 8 (D314). **Ningún permiso miente:** lo que no tiene ruta o se comprueba en código o está
esperando su iteración.

---

## 4. Lo que se lleva la Iteración 7

### 4.1 Decisiones pendientes del dueño del producto

| Pregunta | Origen |
|---|---|
| ¿Un listado filtrado por una sucursal fuera de alcance responde 403 o devuelve vacío? | D292, sigue abierta |
| ¿La pantalla de comandas necesita marcar «preparado»? | D308 |
| ¿`Mesero con cobro` debería poder fiar? | D305 |
| D219, D221, D230 | Abiertas desde la Iteración 3 |

### 4.2 Deuda técnica declarada

- **CFDI: la foto está, el timbre no.** El ticket congela el snapshot fiscal, pero no hay PAC ni CSD: no se emite un CFDI
  timbrado todavía (ADR-005, declarado).
- **El historial de consumos es sólo lo reciente.** El expediente muestra las últimas cincuenta cuentas, sin filtros ni
  exportación; el reporte completo es trabajo de Reportes (Iteración 7). Y sólo lista consumos: los pedidos de comercio
  electrónico (§6.6) llegan con Ecommerce (Iteración 8).
- **`promotions.coupons.manage` sin ruta** hasta la Iteración 8.
- Deuda arrastrada: las pantallas anteriores al paso 20 de la Iteración 4 siguen pintando fechas en la hora del
  navegador (D293) —las tres pantallas nuevas de esta iteración ya usan el ayudante—; `assertBranchInScope()` sigue
  siendo un método estático de un controlador (D292).

### 4.3 Deuda de entorno

`table_definition_cache` sigue en **600** salvo que ya lo hayas subido a 3000 en `my.ini`. Con las seis tablas nuevas de
esta iteración, el margen antes del SQLSTATE 1615 en la suite es más estrecho que antes.

### 4.4 Lo que la Iteración 7 puede dar por hecho

Un negocio puede definir promociones y el POS las aplica solo al cobrar, sin que el POS sepa calcularlas y sin bloquearse
si el módulo no está; y quien cobra ve, antes de cobrar, lo que la promoción descontará. Un cliente tiene expediente:
datos, crédito, perfiles fiscales validados contra el catálogo del SAT, direcciones y su historial de consumos. Y todo
ticket que se cobra a un cliente con perfil fiscal queda **listo para facturar**: la foto fiscal ya está congelada, sólo
falta quien la timbre.

### 4.5 La lección de esta iteración

**El ORM te deja creer que hay una sola lista cuando hay dos.** Un enum de PHP y un `ENUM` de columna, una propiedad del
modelo y una columna generada con sus reglas de MySQL: el código compila, las pruebas unitarias pasan, y el desacuerdo
sólo aparece cuando un valor real cruza hasta la base. Las dos veces que salió mal esta iteración fueron eso, y las dos
las habría atrapado antes una prueba que hiciera pasar un valor **real** por el **esquema real** —no un doble, no un
enum de PHP a solas—. Es la misma familia de «probar con datos de verdad» que ya conocíamos; esta vez la frontera no era
un candado, era la base de datos.
