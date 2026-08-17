# Iteración 2 — Catálogo + Recetas/Costeo · DISEÑO PARA APROBACIÓN

**Estado:** **pendiente de aprobación.** Hay **14 decisiones abiertas** (§11) que los documentos
maestros no cubren. No se escribe ninguna migración ni línea de código hasta que estén resueltas.
**Alcance:** módulos `Catalog` y `Costing` (ARQUITECTURA_MAESTRA §14, iteración 2).

**Fuentes que gobiernan esta iteración:** ESPECIFICACIÓN_MAESTRA §6.1 (es LEY), §7 (glosario
normativo), decisiones **D13 a D22** y **D30**; ARQUITECTURA_MAESTRA §2 (fronteras), §7
(convenciones de datos).

Convenciones aplicadas (idénticas a la Iteración 1, ARQUITECTURA_MAESTRA §7):

- PK `BIGINT UNSIGNED AUTO_INCREMENT`; ULID público sólo en entidades expuestas por API.
- `tenant_id` `BIGINT UNSIGNED NOT NULL` en **toda** tabla. Esta iteración **no agrega ninguna
  excepción** a la Regla A: las cuatro declaradas siguen siendo las únicas del proyecto.
- Timestamps UTC. Sin JSON. Sin *soft deletes* (D80): ciclo de vida con `status`.
- Toda columna de ULID, código y hash en `ascii_bin` (D58): con `utf8mb4_0900_ai_ci` un índice
  único no distinguiría `LIM-01` de `lim-01`.
- Todo índice lleva justificación escrita. Los compuestos empiezan por `tenant_id`.

> ### Vocabulario: esto no es pedantería, es el glosario normativo
>
> **Markup = utilidad ÷ costo.** Es el porcentaje que el sistema configura y con el que sugiere
> precio: `sugerido = costo × (1 + markup)`.
> **Margen = utilidad ÷ precio.** Es lo que muestran los reportes.
>
> Prohibido usarlos como sinónimos en código, columnas, UI o documentación (D13, §7). En este
> diseño la columna se llama `markup_percent` y el margen **no se almacena nunca**: se calcula al
> leer, porque es una consecuencia del precio y del costo, no un dato.

---

## 1. Las tres preguntas que definen la iteración

Antes de las tablas, conviene dejar claro qué es lo difícil aquí. No es el CRUD.

**1. El artículo unificado tiene que ser cuatro cosas a la vez sin volverse ninguna.** D17 prohíbe
tablas separadas de "productos" e "insumos". Las cuatro capacidades —vendible, inventariable,
insumo, producible— son **genuinamente independientes**:

| Ejemplo real | Vendible | Inventariable | Insumo | Producible |
|---|---|---|---|---|
| Cerveza en botella | Sí | Sí | Sí (para michelada) | No |
| Harina | No | Sí | Sí | No |
| Salsa verde preparada en tandas | No | Sí | Sí | Sí |
| Enchiladas (platillo) | Sí | No | No | Sí |
| Servicio de descorche | Sí | No | No | No |

Las 16 combinaciones no son todas válidas, pero las cinco de arriba lo son y ninguna se puede
expresar con un `type` único. Por eso son cuatro banderas y no un enum.

**2. El costeo es un grafo, no una tabla.** Una torta usa pan, y el pan es un artículo producible
con su propia receta, que usa harina. Cambiar el costo de la harina tiene que llegar hasta la torta.
Eso obliga a: detección de ciclos **antes** de guardar (D16), recálculo transitivo en cola, y una
decisión sobre dónde vive el costo vigente (§11, P4).

**3. El precio tiene tres autoridades y hay que respetarlas en orden.** El sistema **sugiere**, el
humano **decide**, y el historial **recuerda** (D15). El diseño tiene que hacer imposible que el
sistema sobrescriba una decisión humana, y tiene que dejar ver la diferencia entre "este precio es
el sugerido" y "alguien decidió este precio".

---

## 2. Módulo `Catalog`

### 2.1 `units` — unidades y conversiones (D22)

```
id                BIGINT UNSIGNED PK
ulid              CHAR(26) ascii_bin        -- expuesta por API
tenant_id         BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
code              VARCHAR(20) ascii_bin NOT NULL     -- 'kg', 'ml', 'pza'
name              VARCHAR(60) NOT NULL               -- 'Kilogramo'
dimension         ENUM('mass','volume','count') NOT NULL
factor_to_base    DECIMAL(18,8) UNSIGNED NOT NULL    -- cuántas unidades base del SISTEMA equivale una de ésta
status            ENUM('active','inactive') DEFAULT 'active'
timestamps
```

**La conversión es por factor a una base fija del sistema, no una tabla de pares.** La base de cada
dimensión es una constante del código: gramo, mililitro, pieza. `kg` tiene `factor_to_base = 1000`.

Convertir entre dos unidades de la misma dimensión es entonces una división de factores, y
—esto es lo importante— **es imposible que el grafo de conversiones sea inconsistente**. Con una
tabla de pares (`from_unit`, `to_unit`, `factor`) alguien puede capturar kg→lb = 2.2 y lb→kg = 0.45,
y las dos filas se contradicen sin que nada lo note. Ver P12.

**Conversión entre dimensiones: no existe, y es deliberado.** No se puede convertir piezas a
kilogramos con una regla global, porque depende del artículo (un limón no pesa lo que una sandía).
Eso lo resuelven las presentaciones de compra (§2.6), que son **por artículo**.

> **Consecuencia que hay que decir en voz alta.** Si el limón tiene unidad base `kg`, la receta
> tiene que decir `0.120 kg`, no `2 piezas`. Si el negocio piensa en piezas, la unidad base del
> limón debe ser `pza` y la compra por costal se expresa con una presentación. La decisión es del
> tenant por artículo y **no se puede cambiar después sin reinterpretar el histórico**, igual que el
> código de una sucursal. La UI tiene que advertirlo.

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `unique(tenant_id, code)` | El código es cómo se elige la unidad al capturar una receta. En `ascii_bin` para que `Kg` y `kg` no colisionen silenciosamente. |
| `index(tenant_id, dimension, status)` | El selector de unidad de una línea de receta pide "unidades activas de la dimensión del insumo" (invariante I3). Es la única consulta de esta tabla que no es por PK. |

### 2.2 `article_categories` — dos niveles (D18)

```
id            BIGINT UNSIGNED PK
ulid          CHAR(26) ascii_bin
tenant_id     BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
parent_id     BIGINT UNSIGNED NULL      FK article_categories RESTRICT
level         TINYINT UNSIGNED NOT NULL          -- 1 o 2, y nada más
name          VARCHAR(80) NOT NULL
sort_order    SMALLINT UNSIGNED DEFAULT 0
status        ENUM('active','inactive') DEFAULT 'active'
timestamps
```

`CHECK chk_article_categories_depth`: `(level = 1 AND parent_id IS NULL) OR (level = 2 AND parent_id
IS NOT NULL)`. Es el mismo patrón redundante-con-CHECK que `warehouses.kind` de la Iteración 1: la
columna hace explícito en el modelo lo que si no sería convención tácita, y el CHECK impide que las
dos afirmaciones se contradigan.

`CHECK` no puede impedir que una categoría de nivel 2 apunte a otra de nivel 2 (un CHECK no consulta
otras filas). Eso lo impone el servicio de aplicación y lo verifica una prueba. **D18 dice dos
niveles y esto los da; un tercer nivel exige decisión, no una migración.**

`parent_id` es `RESTRICT` y no `CASCADE` a propósito: borrar una categoría padre no debe llevarse
las subcategorías —y con ellas la clasificación de los artículos— por un clic.

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `index(tenant_id, parent_id, sort_order)` | La única consulta real: "el árbol de categorías de este negocio, en orden". Resuelve el listado completo con un solo recorrido. |

**Unicidad del nombre:** ver P2. Es el mismo problema de `NULL` en índice único que resolvió D78, y
aparece aquí y en los precios por sucursal, así que conviene decidirlo **una vez** para el proyecto.

### 2.3 `tags` y `article_tag` — etiquetas libres (D19)

```
tags
  id, ulid, tenant_id NOT NULL FK, name VARCHAR(60) NOT NULL, timestamps
  unique(ulid) · unique(tenant_id, name)

article_tag
  tenant_id NOT NULL FK, article_id NOT NULL FK CASCADE, tag_id NOT NULL FK CASCADE
  PK compuesta (article_id, tag_id)
  index(tenant_id, tag_id)   -- "todos los artículos con esta etiqueta"
```

`article_tag` lleva `tenant_id` aunque sea alcanzable por FK: la Regla A no admite excepciones de
conveniencia, y ADR-002 lo dice explícitamente ("aunque sea alcanzable por FKs").

Las etiquetas son libres (D19), así que **no** tienen `status`: una etiqueta que ya no se usa se
borra, y `article_tag` cae con ella por `CASCADE`. No hay histórico que preservar.

### 2.4 `articles` — el artículo unificado (D17)

```
id                        BIGINT UNSIGNED PK
ulid                      CHAR(26) ascii_bin
tenant_id                 BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
code                      VARCHAR(40) ascii_bin NULL      -- SKU opcional (P10)
name                      VARCHAR(160) NOT NULL
short_name                VARCHAR(40) NULL                -- para comanda y botón de POS
category_id               BIGINT UNSIGNED NULL  FK article_categories RESTRICT
base_unit_id              BIGINT UNSIGNED NOT NULL  FK units RESTRICT

-- Capacidades (D17). Cuatro banderas independientes; ver la tabla de §1.
is_sellable               BOOLEAN NOT NULL DEFAULT FALSE
is_inventoriable          BOOLEAN NOT NULL DEFAULT FALSE
is_supply                 BOOLEAN NOT NULL DEFAULT FALSE
is_producible             BOOLEAN NOT NULL DEFAULT FALSE

-- Precio: dato maestro CON IVA INCLUIDO (D30)
base_price                DECIMAL(12,2) NULL              -- obligatorio si is_sellable (I2)
markup_percent            DECIMAL(6,2) NULL               -- override del ajuste del tenant (P6)

-- Costo vigente: PROYECCIÓN, no verdad. La verdad es `article_costs` (P4)
current_unit_cost         DECIMAL(12,4) NULL              -- por unidad base del artículo (P3)
current_cost_effective_at TIMESTAMP NULL
current_cost_id           BIGINT UNSIGNED NULL  FK article_costs SET NULL

is_available_in_pos       BOOLEAN NOT NULL DEFAULT TRUE
status                    ENUM('active','archived') NOT NULL DEFAULT 'active'
timestamps
```

**`short_name` existe porque una comanda es papel de 58 mm.** "Enchiladas suizas de pollo con
frijoles refritos" no cabe, y dejar que el POS trunque produce comandas ambiguas para la cocina, que
es exactamente donde una ambigüedad cuesta un platillo. Nullable: si falta, se usa `name`.

**`base_price` es con IVA incluido (D30) y el desglose se calcula:**

```
subtotal = base_price / (1 + tasa)
iva      = base_price − subtotal
```

La tasa sale de la configuración jerárquica que ya existe (`tax.vat_rate`, ámbito máximo sucursal).
**Esta iteración no almacena ni el subtotal ni el IVA en ninguna parte**: son consecuencias de un
dato maestro y de un ajuste, y almacenarlos crearía una segunda fuente que quedaría desfasada el día
que el tenant cambie la tasa. La reconciliación de redondeo a nivel ticket —que la suma de subtotales
de línea cuadre con el subtotal del ticket— es un problema real y pertenece a la Iteración 4 (POS) y
5 (Finanzas); queda anotado aquí para que nadie lo descubra tarde.

**`current_unit_cost` es una proyección declarada, no una verdad paralela.** Es el mismo patrón que
la propia especificación usa en inventarios: "Kardex como fuente de verdad; existencia como
acumulado" (§6.2). La verdad es la última fila de `article_costs`; esta columna existe porque costear
una receta de 30 líneas exigiría 30 consultas de "última fila por artículo", y costear un platillo
con sub-recetas anidadas las multiplica. Ver P4, incluido el comando de reconstrucción.

`current_cost_id` apunta a la fila del historial que produjo el valor. Sin eso, "de dónde salió este
costo" es una pregunta que se contesta adivinando por fecha.

#### Invariantes

| | Invariante | Dónde se impone |
|---|---|---|
| **I1** | `is_producible = true` ⇒ tiene a lo más **una** receta activa | `unique` parcial en `recipes` (§3.1) |
| **I2** | `is_sellable = true` ⇒ `base_price IS NOT NULL` | Form Request al crear y al activar. **No** es CHECK: se permite capturar un artículo y ponerle precio después, pero no venderlo sin precio |
| **I3** | La unidad de una línea de receta comparte `dimension` con la unidad base del componente | Servicio de aplicación + prueba |
| **I4** | El grafo de recetas es acíclico | Detección de ciclos antes de guardar (§3.3) |
| **I5** | Un componente de receta tiene `is_supply = true` | Form Request. Es lo que hace explícita la doble modalidad de D16 |
| **I6** | `base_unit_id` no cambia si el artículo tiene costos, recetas o movimientos | Servicio de aplicación. Cambiarla reinterpretaría todo el histórico de cantidades |

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `unique(tenant_id, code)` | SKU único por negocio. `code` es nullable y MySQL no deduplica NULL en índices únicos — que aquí es **exactamente** lo deseado: muchos artículos sin SKU (P10). |
| `index(tenant_id, status, is_sellable, name)` | La consulta más frecuente del sistema: el catálogo vendible del POS, ordenado por nombre. Cubre además la búsqueda por prefijo del selector de artículos. Cuatro columnas porque las tres primeras son igualdad y la cuarta es el orden y el rango. |
| `index(tenant_id, category_id, status)` | Navegación por categoría en POS y en la pantalla de catálogo, y base de las promociones por categoría (D50, Iteración 7). |
| `index(tenant_id, is_supply, status)` | El selector de insumos al capturar una receta. Sin él, elegir un insumo recorre el catálogo completo, que en un restaurante con 800 artículos es el 90 % de la tabla. |

**Índices que NO se crean, y por qué:** ninguno sobre `is_inventoriable` ni `is_producible`. Sus
consultas ("todos los inventariables") son de administración, de baja frecuencia y sobre tablas de
cientos de filas, no de cientos de miles. Un índice sin justificación escrita está prohibido, y
"podría servir" no es justificación.

### 2.5 `article_branch_overrides` — precio y disponibilidad por sucursal

```
id                     BIGINT UNSIGNED PK
tenant_id              BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
article_id             BIGINT UNSIGNED NOT NULL  FK articles CASCADE
branch_id              BIGINT UNSIGNED NOT NULL  FK branches CASCADE
price                  DECIMAL(12,2) NULL     -- NULL = hereda articles.base_price
is_available_in_pos    BOOLEAN NULL           -- NULL = hereda articles.is_available_in_pos
timestamps

unique(tenant_id, article_id, branch_id)
index(tenant_id, branch_id)     -- "el catálogo con overrides de esta sucursal", en una sola pasada
```

**Una tabla con dos dimensiones de override y `NULL` = heredar**, exactamente como la cascada de
configuración del kernel. Ventaja concreta: `branch_id` es `NOT NULL`, así que el índice único
funciona sin trucos y sin el problema de `NULL` de D78.

**La cascada es de dos niveles y nada más:** override de sucursal → dato maestro del artículo. Dos
niveles se explican en una frase y se prueban en cuatro casos.

**El canal (POS / e-commerce) NO está en esta iteración.** §6.1 pide "override por sucursal y por
canal", y lo diferimos con deuda declarada: en v1 sólo existe un canal transaccional —el POS—, porque
e-commerce es la Iteración 9 y es un módulo activable. Construir ahora la dimensión de canal
significaría diseñar y probar una cascada de cuatro niveles contra un solo canal, es decir, sin poder
verificar que sirve. Cuando llegue la Iteración 9 se agrega una columna `channel` y se amplía el
índice único: una migración aditiva de una tabla que en ese momento tendrá los datos reales para
probarla. **Deuda declarada, no silenciosa.**

### 2.6 `article_purchase_presentations` — presentaciones de compra (D22)

```
id                      BIGINT UNSIGNED PK
ulid                    CHAR(26) ascii_bin
tenant_id               BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
article_id              BIGINT UNSIGNED NOT NULL  FK articles CASCADE
name                    VARCHAR(80) NOT NULL         -- 'Costal de 25 kg', 'Caja con 24'
quantity_in_base_unit   DECIMAL(12,4) UNSIGNED NOT NULL   -- 25.0000 · 24.0000
barcode                 VARCHAR(32) ascii_bin NULL
is_default              BOOLEAN NOT NULL DEFAULT FALSE
status                  ENUM('active','inactive') DEFAULT 'active'
timestamps

unique(ulid) · index(tenant_id, article_id, status) · index(tenant_id, barcode)
```

Van en esta iteración y no en la 3 aunque las compras sean de la Iteración 3, porque **la captura
manual de costo las necesita ya**: "compré un costal de 25 kg en $600" es la forma en que un dueño
piensa el costo, y sin presentación habría que pedirle que divida a mano — que es justo el tipo de
cálculo donde se equivoca y contamina el costeo entero.

`quantity_in_base_unit` está en la unidad base del artículo, así que no hace falta `unit_id`: la
presentación es un múltiplo, no otra unidad.

Es también la vía de la conversión entre dimensiones que §2.1 prohíbe globalmente: aquí es
**por artículo**, que es el único nivel en que la afirmación es cierta.

### 2.7 `price_changes` — historial inmutable de precios (D15, §7)

```
id                     BIGINT UNSIGNED PK
ulid                   CHAR(26) ascii_bin
tenant_id              BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
article_id             BIGINT UNSIGNED NOT NULL  FK articles RESTRICT
branch_id              BIGINT UNSIGNED NULL      FK branches RESTRICT   -- NULL = cambió el precio maestro
previous_price         DECIMAL(12,2) NULL        -- NULL = no había precio (primera fijación)
new_price              DECIMAL(12,2) NOT NULL
suggested_price        DECIMAL(12,4) NULL        -- el que el sistema sugería EN ESE MOMENTO
unit_cost_at_change    DECIMAL(12,4) NULL        -- y el costo con el que lo sugirió
markup_percent         DECIMAL(6,2) NULL         -- y el markup aplicado
reason                 VARCHAR(200) NULL
actor_membership_id    BIGINT UNSIGNED NULL  FK tenant_memberships RESTRICT
created_at             TIMESTAMP NOT NULL        -- escrito por PHP, no por la base (D85)
```

**Inmutable** con el trait `Immutable` del kernel: sin `UPDATE` ni `DELETE`, sin `updated_at`, y con
`created_at` escrito desde PHP porque `useCurrent()` lo escribiría con la zona horaria de MySQL
(D85 — costó descubrirlo en la Iteración 1, no se repite).

**Las FK de `article_id` y `actor_membership_id` son `RESTRICT`, no `CASCADE`.** Es la misma decisión
que en `audit_entries.actor_user_id`: un historial de precios es evidencia, y borrar a la persona que
subió un precio no puede borrar el hecho de que lo subió. Por eso los artículos se **archivan**
(D80), no se borran.

**`suggested_price`, `unit_cost_at_change` y `markup_percent` se guardan aunque sean derivables.**
Aquí sí, y la diferencia con el subtotal de IVA de §2.4 es exactamente la que importa: el IVA se
recalcula igual mañana; el costo y el markup de hace ocho meses **ya no se pueden reconstruir**
—cambiaron— y sin ellos la pregunta que este historial existe para contestar ("¿el precio se subió
porque subió el costo, o porque alguien quiso?") no tiene respuesta.

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `index(tenant_id, article_id, created_at)` | "El historial de precios de este artículo", lo más reciente primero. Es la pantalla que D15 exige. |
| `index(tenant_id, created_at)` | "Todos los cambios de precio del mes", el reporte de control que §9 pide como mitigación de manipulación de precios. Va por separado porque no filtra por artículo. |

Tabla de alto volumen a futuro (un cambio masivo de precios escribe una fila por artículo y por
sucursal). Particionamiento lógico por fecha queda como evolución, igual que en auditoría.

### 2.8 Modificadores (D7, §6.1)

```
modifier_groups
  id, ulid, tenant_id NOT NULL FK CASCADE
  name              VARCHAR(80) NOT NULL      -- 'Término de la carne'
  is_required       BOOLEAN NOT NULL DEFAULT FALSE
  min_selections    TINYINT UNSIGNED NOT NULL DEFAULT 0
  max_selections    TINYINT UNSIGNED NULL     -- NULL = sin límite
  allows_quantity   BOOLEAN NOT NULL DEFAULT FALSE   -- '3 shots' (D7)
  status            ENUM('active','inactive') DEFAULT 'active'
  timestamps
  unique(ulid) · unique(tenant_id, name) · index(tenant_id, status)

  CHECK chk_modifier_groups_selections:
     (max_selections IS NULL OR max_selections >= min_selections)
     AND (is_required = FALSE OR min_selections >= 1)

modifiers
  id, ulid, tenant_id NOT NULL FK CASCADE
  modifier_group_id NOT NULL FK modifier_groups CASCADE
  name              VARCHAR(80) NOT NULL      -- 'Término medio', 'Extra queso'
  extra_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00   -- CON IVA incluido (D30). Ver P14
  sort_order        SMALLINT UNSIGNED DEFAULT 0
  status            ENUM('active','inactive') DEFAULT 'active'
  timestamps
  unique(ulid) · index(tenant_id, modifier_group_id, sort_order, status)

article_modifier_group
  tenant_id NOT NULL FK, article_id NOT NULL FK CASCADE, modifier_group_id NOT NULL FK CASCADE
  sort_order SMALLINT UNSIGNED DEFAULT 0
  PK (article_id, modifier_group_id)
  index(tenant_id, modifier_group_id)   -- "¿qué artículos usan este grupo?", antes de editarlo
```

**Los grupos son del tenant y se reutilizan**, no son propiedad de un artículo. "Término de la carne"
lo comparten ocho cortes, y duplicarlo ocho veces garantiza que se editen siete. El pivote sólo
ordena.

**Las reglas viven en el grupo y no se pueden sobrescribir por artículo** (ver P8): un artículo que
necesita reglas distintas usa un grupo distinto. Evita una cascada de dos niveles en la validación
más caliente del POS —"¿puedo enviar esta orden?"— donde una regla ambigua se convierte en un
platillo mal preparado.

**El impacto en receta de un modificador** (§6.1: "modificador con precio adicional e impacto en
receta por unidad") se modela con una receta cuyo dueño es el modificador, no el artículo. Ver §3.1.

---

## 3. Módulo `Costing`

### 3.1 `recipes` — la cabecera

```
id                BIGINT UNSIGNED PK
ulid              CHAR(26) ascii_bin
tenant_id         BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
article_id        BIGINT UNSIGNED NULL  FK articles CASCADE     -- dueño: artículo…
modifier_id       BIGINT UNSIGNED NULL  FK modifiers CASCADE    -- …o modificador
output_quantity   DECIMAL(12,4) UNSIGNED NOT NULL DEFAULT 1     -- la receta rinde N…
output_unit_id    BIGINT UNSIGNED NOT NULL  FK units RESTRICT   -- …en esta unidad
notes             VARCHAR(500) NULL
status            ENUM('active','inactive') NOT NULL DEFAULT 'active'
timestamps

CHECK chk_recipes_single_owner:
    (article_id IS NOT NULL AND modifier_id IS NULL)
 OR (article_id IS NULL AND modifier_id IS NOT NULL)
```

**Dos FK nullable con `CHECK` de exclusividad, no una relación polimórfica.** Un `owner_type` /
`owner_id` no tiene integridad referencial: nada impide una receta huérfana apuntando a un
`article_id` borrado, y el día que aparezca la fila huérfana el costeo devolverá un número sin
explicación. El precedente del proyecto es el mismo: `warehouses` usa un `CHECK` para la
contradicción entre `kind` y `branch_id`, verificado contra la base real.

**`output_quantity` es lo que hace posible el costeo en cascada de D16.** Una receta de salsa cuesta
$100 y rinde 2 L; el costo por litro es $50, y es ese número el que entra en la receta de las
enchiladas. Sin la cabecera no habría dónde poner el rendimiento y el costo de una sub-receta sería
indefinido.

Para una receta de modificador, `output_quantity` es 1 en la unidad del modificador: "extra queso"
rinde una porción.

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `unique(tenant_id, article_id)` **parcial vía NULL** | Impone **I1**: a lo más una receta por artículo. Funciona porque MySQL no deduplica NULL, así que las recetas de modificador (con `article_id` NULL) no colisionan entre sí. |
| `unique(tenant_id, modifier_id)` | Lo mismo del otro lado. |

### 3.2 `recipe_lines` — los componentes (D21)

```
id                     BIGINT UNSIGNED PK
tenant_id              BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
recipe_id              BIGINT UNSIGNED NOT NULL  FK recipes CASCADE
component_article_id   BIGINT UNSIGNED NOT NULL  FK articles RESTRICT
quantity               DECIMAL(12,4) UNSIGNED NOT NULL
unit_id                BIGINT UNSIGNED NOT NULL  FK units RESTRICT
yield_percent          DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 100.00
sort_order             SMALLINT UNSIGNED DEFAULT 0
timestamps

unique(recipe_id, component_article_id)
CHECK chk_recipe_lines_yield: yield_percent > 0 AND yield_percent <= 100
```

**`yield_percent` es el rendimiento de D21 y divide, no multiplica.** Si la receta pide 200 g de
cebolla utilizable y el rendimiento es 80 %, hay que comprar 250 g: `costo_línea = costo_base ×
cantidad ÷ (rendimiento/100)`. Es el sentido que la operación exige —la merma de limpieza la paga el
platillo— y el error de signo aquí subvalúa sistemáticamente todos los costos, así que va con prueba
propia.

`> 0` en el CHECK: un rendimiento de 0 sería una división por cero, es decir, un costo infinito.

`component_article_id` es `RESTRICT`: un insumo usado en recetas no se borra. Se archiva.

| Índice | Justificación |
|---|---|
| `unique(recipe_id, component_article_id)` | El mismo insumo dos veces en una receta son dos cantidades que alguien sumará mal. Se captura una línea con la cantidad total. |
| `index(tenant_id, component_article_id)` | **El índice más importante de la iteración.** Es la dirección inversa del grafo —"¿qué recetas usan este insumo?"— y la recorre dos cosas: el recálculo transitivo cuando cambia un costo, y el análisis de impacto que se le muestra al usuario **antes** de capturar un costo nuevo. Sin él, cada cambio de costo recorre la tabla completa de líneas de receta. |

### 3.3 Detección de ciclos (D16) — obligatoria y antes de guardar

Al insertar o modificar una línea con componente `C` en la receta cuyo dueño es el artículo `A`:

1. Si `C = A`, se rechaza. Es el ciclo trivial y el más probable por error de dedo.
2. Se recorre hacia abajo desde `C` por `recipe_lines` (el grafo "usa a"). Si en algún punto se
   alcanza `A`, se rechaza con el **camino completo** en el mensaje: *"Pan → Masa → Torta"* dice
   dónde está el problema; *"se detectó un ciclo"* obliga a buscarlo a mano.

Se valida **antes** de escribir, dentro de la transacción, y no con un job posterior: un ciclo
guardado hace que el recálculo no termine nunca, y descubrirlo en producción significa una cola
atascada.

El recorrido se hace en memoria sobre el conjunto de líneas del tenant. Es aceptable porque un
catálogo de recetas son miles de líneas, no millones; si algún día no lo fuera, la salida es una
tabla de cierre transitivo, y sería una decisión con ADR.

### 3.4 `article_costs` — historial inmutable de costos (D14)

```
id                     BIGINT UNSIGNED PK
ulid                   CHAR(26) ascii_bin
tenant_id              BIGINT UNSIGNED NOT NULL  FK tenants CASCADE
article_id             BIGINT UNSIGNED NOT NULL  FK articles RESTRICT
unit_cost              DECIMAL(12,4) UNSIGNED NOT NULL   -- por unidad base del artículo (P3)
origin                 ENUM('initial','manual','purchase','recipe_cascade') NOT NULL
source_cost_id         BIGINT UNSIGNED NULL  FK article_costs RESTRICT  -- qué cambio lo disparó
idempotency_key        VARCHAR(100) ascii_bin NULL
notes                  VARCHAR(200) NULL
actor_membership_id    BIGINT UNSIGNED NULL  FK tenant_memberships RESTRICT  -- NULL = lo calculó un job
effective_at           TIMESTAMP NOT NULL
created_at             TIMESTAMP NOT NULL      -- escrito por PHP (D85)
```

**Inmutable.** Es una de las seis tablas que §7 declara sin `UPDATE` ni `DELETE`.

**`origin` distingue el costo de adquisición del costo calculado**, que es la tensión de P5: D14 dice
"costo vigente = último costo **de adquisición**", y un platillo no tiene adquisición: su costo se
calcula. Los dos viven en la misma tabla con `origin` distinguiéndolos, porque el usuario quiere una
sola pantalla de "cómo evolucionó el costo de mis enchiladas" y partirla en dos historiales
obligaría a unirlas en la lectura para siempre.

**`source_cost_id` da la cadena causal.** "El costo de la torta cambió porque cambió el costo del
jitomate", con enlace. Es lo que convierte el historial en algo investigable en lugar de una lista de
números con fechas.

**`idempotency_key` es el requisito de idempotencia de CLAUDE.md hecho columna.** El recálculo en
cascada es un job, y re-despacharlo **no puede** duplicar historial. La llave la construye el job de
forma determinista a partir de lo que lo disparó (el `article_costs` de origen, o la receta y su
versión), y el índice único la hace imposible de violar aunque el código se equivoque. Nullable:
las capturas manuales no la necesitan y MySQL no deduplica NULL.

| Índice | Justificación |
|---|---|
| `unique(ulid)` | Identificador público. |
| `index(tenant_id, article_id, effective_at)` | Las dos consultas de la tabla: "el costo vigente" (última fila) y "el historial de este artículo". Y el promedio del periodo de D14, que es un `AVG` sobre este mismo rango. |
| `unique(tenant_id, idempotency_key)` | Idempotencia estructural del job de recálculo (arriba). |

**El promedio del periodo se calcula, no se almacena.** D14 es explícito: es referencia visual y no
se usa para cálculo. Almacenarlo sería crear la segunda fuente que la propia decisión prohíbe.

### 3.5 El cálculo, escrito de una vez

Para un artículo **no producible**: `costo = unit_cost` de la última fila de `article_costs`.

Para un artículo **producible** con receta `R` que rinde `Q_out` en unidad `U_out`:

```
por cada línea L de R:
    factor      = L.unit.factor_to_base / componente.base_unit.factor_to_base
    cantidad    = L.quantity × factor                    -- en la unidad base del componente
    costo_línea = costo(componente) × cantidad ÷ (L.yield_percent / 100)

total        = Σ costo_línea
rinde_en_base_del_artículo = Q_out × (U_out.factor_to_base / artículo.base_unit.factor_to_base)
costo(A)     = total ÷ rinde_en_base_del_artículo
```

`costo(componente)` es recursivo y termina porque I4 garantiza un DAG. Se mantienen **4 decimales**
en todo el cálculo intermedio y sólo se redondea al presentar: redondear a dos en cada línea de una
receta de 30 componentes acumula un error que se nota en el margen.

**Precio sugerido y semáforo (D15):**

```
sugerido_sin_redondear = costo × (1 + markup/100)
sugerido               = redondear(sugerido_sin_redondear, pricing.rounding_mode)
margen                 = (precio_final − costo) ÷ precio_final     -- para reportes, NUNCA markup
desactualizado         = |precio_final − sugerido| ÷ sugerido > tolerancia
```

`markup` sale de `articles.markup_percent` y, si es NULL, del ajuste `pricing.default_markup_percent`
que ya existe en el catálogo de configuración. `pricing.rounding_mode` también existe ya, con sus
cuatro valores. La `tolerancia` es un ajuste **nuevo** y por tanto una decisión (P13): sin ella, el
propio redondeo configurado marcaría todos los precios como desactualizados el primer día.

---

## 4. Eventos y fronteras

**Eventos que emite `Catalog`:**

| Evento | Cuándo | Quién lo consume |
|---|---|---|
| `ArticleCreated` · `ArticleArchived` | alta y archivado | Nadie en v1. Menús digitales y e-commerce (Iteración 9) invalidan cache |
| `ArticlePriceChanged` | cambia precio maestro o de sucursal | Iteración 9 (cache pública). El historial lo escribe `Catalog` en la misma transacción: es historia de dominio propia, no efecto cruzado |
| `ArticleAvailabilityChanged` | cambia disponibilidad en POS | POS (Iteración 4) para refrescar el catálogo en pantalla vía Reverb |

**Eventos que emite `Costing`:**

| Evento | Cuándo | Quién lo consume |
|---|---|---|
| `ArticleCostChanged` | se escribe una fila en `article_costs` | `Costing` mismo, para el recálculo transitivo. Inventarios (Iteración 3) valúa movimientos al costo |
| `RecipeChanged` | alta o cambio de receta o de líneas | `Costing`, para recostear al dueño y a sus dependientes |

**Jobs:**

`RecalculateDependentCosts(article_id, source_cost_id)` en la cola `default` — **no** `critical`: un
costo no es verdad contable y el POS no se detiene por él. Idempotente por `idempotency_key`
(§3.4). Recorre `recipe_lines` en la dirección inversa (índice de §3.2), recostea cada dependiente y
se re-despacha para sus propios dependientes. Termina porque el grafo es acíclico.

### 4.1 La frontera entre `Catalog` y `Costing` — decisión P1

Hay un hecho ineludible: `recipe_lines.component_article_id` es una **FK a una tabla de otro
módulo**. No se puede evitar sin duplicar el catálogo, y el kernel ya tiene FK cruzadas
(`audit_entries → users`). Así que la dependencia de datos existe; lo que hay que decidir es la
dependencia de **código**, y el candado actual no vigila nada de esto: `ModuleBoundariesTest` sólo
comprueba que el kernel no dependa de módulos de dominio.

La regla que propongo, y que la decisión P1 debe aprobar o rechazar:

1. `Costing` **lee** `Catalog` a través de sus servicios públicos de `Application/` y por FK.
2. `Costing` **nunca escribe** en tablas de `Catalog`. Aceptar un precio sugerido pasa por el
   servicio de `Catalog`, que es quien escribe `articles.base_price` y `price_changes`.
3. `Catalog` **no conoce** `Costing`. El precio sugerido y el margen se piden a `Costing` desde la
   capa HTTP, que puede depender de los dos, no desde el dominio de `Catalog`.
4. Las aristas permitidas se **declaran** en `config/comandia.php` (`'depends_on' => ['Catalog']`) y
   el candado de fronteras las **impone**: cualquier referencia a un módulo no declarado falla.

El punto 4 es lo que hace que esto sea arquitectura y no una buena intención. Hoy dos módulos de
dominio pueden acoplarse en ambos sentidos sin que nada proteste, y ése es el camino por el que un
monolito modular deja de serlo.

---

## 5. Configuración: lo que ya existe y lo que falta

Ya en el catálogo del kernel, sin cambios: `tax.vat_rate` (ámbito sucursal),
`pricing.default_markup_percent`, `pricing.rounding_mode`, `inventory.warehouse_mode`.

**Ajuste nuevo propuesto** (P13), con su caso de uso escrito como exige D20:

| Llave | Tipo | Default | Ámbito | Caso de uso |
|---|---|---|---|---|
| `pricing.stale_price_tolerance_percent` | decimal | 5.00 | tenant | El semáforo de "precio desactualizado" de D15 necesita un umbral. Sin él, el redondeo configurado por el propio tenant marcaría en rojo el 100 % del catálogo el primer día, y un semáforo que siempre está en rojo no lo mira nadie |

---

## 6. Permisos

**Ninguno nuevo.** D72 sembró el catálogo completo desde la Iteración 1, y los 16 permisos de
`Catalog` y `Costing` cubren esta iteración. Reparto propuesto sobre los roles plantilla:

| Permiso | Propietario | Gerente | Almacenista | Cajero | Mesero |
|---|---|---|---|---|---|
| `catalog.articles.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `catalog.articles.manage` · `.archive` | ✓ | ✓ | — | — | — |
| `catalog.prices.view` | ✓ | ✓ | — | ✓ | ✓ |
| **`catalog.prices.update`** | ✓ | ✓ | — | — | — |
| `catalog.prices.history.view` | ✓ | ✓ | — | — | — |
| `catalog.categories.manage` · `tags.manage` · `units.manage` · `modifiers.manage` | ✓ | ✓ | — | — | — |
| `costing.recipes.view` | ✓ | ✓ | ✓ | — | — |
| `costing.recipes.manage` | ✓ | ✓ | — | — | — |
| `costing.costs.view` · `.history.view` | ✓ | ✓ | ✓ | — | — |
| **`costing.costs.update`** | ✓ | ✓ | ✓ | — | — |
| `costing.suggested_prices.view` | ✓ | ✓ | — | — | — |

Un mesero ve precios porque los dice en voz alta; no ve costos, porque el costo es información
sensible del negocio. El almacenista **sí** captura costos: es quien recibe la mercancía y ve la
factura del proveedor.

**`catalog.prices.update` es zona de auditoría.** Todo cambio escribe en `price_changes` con actor, y
además en la bitácora técnica: §6.7 lista los precios entre las acciones que la bitácora vigila.

---

## 7. API — endpoints de la iteración

Paginación por **página** (son catálogos, no transaccional), salvo los dos historiales, que van por
**cursor**. Whitelist de filtros por endpoint. Todo bajo `/api/v1`.

```
GET    /units                          POST /units            PATCH /units/{ulid}
GET    /article-categories             POST …                 PATCH …
GET    /tags                           POST …                 DELETE /tags/{ulid}

GET    /articles                       -- filtros: search, category, status, capability, available_in_pos
POST   /articles
GET    /articles/{ulid}                -- incluye capacidades, precio, costo vigente, sugerido y margen
PATCH  /articles/{ulid}
POST   /articles/{ulid}/archive
PUT    /articles/{ulid}/price          -- CAMBIO DE PRECIO: permiso propio + historial + bitácora
PUT    /articles/{ulid}/branches/{branch}/override
DELETE /articles/{ulid}/branches/{branch}/override
GET    /articles/{ulid}/price-changes   -- cursor
GET    /articles/{ulid}/costs           -- cursor. Incluye el promedio del periodo como referencia
POST   /articles/{ulid}/costs           -- captura manual; acepta presentación de compra
GET    /articles/{ulid}/presentations   POST … PATCH … 
GET    /articles/{ulid}/modifier-groups PUT  …               -- sincroniza el pivote

GET    /modifier-groups                POST … PATCH …
POST   /modifier-groups/{ulid}/modifiers               PATCH /modifiers/{ulid}

GET    /articles/{ulid}/recipe          PUT /articles/{ulid}/recipe    -- receta completa con líneas
GET    /modifiers/{ulid}/recipe         PUT /modifiers/{ulid}/recipe
GET    /articles/{ulid}/cost-breakdown  -- el desglose del cálculo de §3.5, línea por línea
GET    /articles/{ulid}/impact          -- "qué recetas usan esto", ANTES de cambiar un costo
```

`PUT` para la receta completa y no `POST`/`DELETE` por línea: una receta es una unidad de sentido, y
guardarla en una transacción permite validar ciclos y suma de rendimientos **una vez**, sobre el
estado final. Con operaciones por línea, un estado intermedio inválido es inevitable.

`GET /articles/{ulid}/cost-breakdown` existe porque un costo sin desglose es un número que nadie
cree. Es la pantalla que convence al dueño de que el sistema no se equivocó.

---

## 8. Qué NO entra en esta iteración

Cada una es una omisión deliberada, con su iteración destino:

| Fuera de alcance | Va en | Por qué no aquí |
|---|---|---|
| Override de precio **por canal** | 9 | En v1 sólo hay un canal transaccional. Migración aditiva cuando exista el segundo (§2.5) |
| Disponibilidad en menú y en tienda | 9 | Son estados de la capa de publicación de ADR-007, propiedad de módulos activables |
| **Menú por horario** ("desayunos hasta 13:00") | 4 | Su único consumidor es el POS y su modelo de menú. Diseñar las ventanas de disponibilidad sin él es diseñarlas a ciegas. **Es requisito de §6.1: deuda declarada, no olvido** |
| Ruteo de comandas por artículo (área de preparación) | 4 | Es atributo del artículo, pero su semántica la define el modelo de comanda, y probablemente sea por sucursal |
| Imágenes y galería del artículo | 9 | Capa de publicación (ADR-007) |
| Movimientos de inventario al producir una sub-receta | 3 | Producir salsa consume insumos y genera existencia: es kardex |
| Costo por proveedor y recepción de compra | 3 | `article_costs.origin = 'purchase'` queda previsto; el documento origen llega con compras |
| Promociones por categoría | 7 | El índice `(tenant, category_id, status)` ya las soporta |

---

## 9. Pruebas de la iteración (Definition of Done)

**Unidad de dominio** — donde está el riesgo real:

- Conversión de unidades dentro de la dimensión, y **rechazo** entre dimensiones.
- Costeo de receta plana, con rendimiento, y con `output_quantity ≠ 1`.
- Costeo **en cascada** de tres niveles (harina → pan → torta) y verificación de que el número final
  es el correcto a 4 decimales.
- **Detección de ciclos**: directo (A→A), indirecto (A→B→A) y de tres saltos, con el camino en el
  mensaje.
- Rendimiento que **divide** y no multiplica (el error que subvalúa todo el catálogo).
- Precio sugerido con los cuatro modos de redondeo.
- **Markup ≠ margen**: una prueba que fija costo 100 y markup 200 %, y verifica sugerido 300 y margen
  66.67 %. Es el candado del glosario en forma ejecutable.

**Feature de API:** un caso por endpoint, con Form Request y Resource.

**Aislamiento de tenant del módulo** (obligatorio): barrido de las 14 tablas nuevas, con el mismo
patrón de simetría de la Iteración 1.

**Autorización:** `catalog.prices.update` y `costing.costs.update` negados a los roles que no los
tienen, verificando **rol activo** y no suma de roles.

**Idempotencia del job** de recálculo: despachar dos veces la misma llave no duplica historial.

**Inmutabilidad** de `price_changes` y `article_costs` por las tres vías que ya usa el kernel
(evento, `update()` del modelo y *query builder*).

**Candado estructural nuevo:** las aristas de dependencia entre módulos declaradas en el registro se
imponen (§4.1, P1).

---

## 10. Orden de implementación propuesto

1. `units` + conversiones + servicio de conversión, con sus pruebas. **Todo lo demás depende de esto**
   y un error aquí contamina cada costo del sistema.
2. `article_categories`, `tags`.
3. `articles` con capacidades e invariantes I2 e I6.
4. `article_purchase_presentations` + captura manual de costo → `article_costs`.
5. `recipes` + `recipe_lines` + **detección de ciclos** (antes del costeo: sin ella el costeo puede no
   terminar).
6. Motor de costeo en cascada + `cost-breakdown`.
7. Job de recálculo transitivo + idempotencia + `impact`.
8. Precio, `price_changes`, precio sugerido, semáforo y el ajuste nuevo.
9. `article_branch_overrides`.
10. Modificadores + grupos + pivote + recetas de modificador.
11. Aislamiento, matriz de autorización y candado de fronteras.
12. UI de administración del catálogo.

---

## 11. Decisiones que los documentos maestros NO cubren — **ABIERTAS**

Ninguna se resuelve sola y ninguna se implementa antes de tu decisión. Las tres primeras cambian el
esquema; las demás cambian comportamiento o alcance.

| | Asunto | Mi recomendación |
|---|---|---|
| **P1** | ¿Cómo se declara y se impone la dependencia `Costing → Catalog`? | Lectura por servicios públicos + FK, prohibición de escritura, aristas declaradas en el registro e impuestas por candado |
| **P2** | Unicidad con columna `NULL` (nombre de categoría, y en general): ¿columna generada, dos tablas, o validación en aplicación? | Decidirlo **una vez** para el proyecto; recomiendo columna generada `STORED` |
| **P3** | Precisión del costo unitario: `DECIMAL(12,4)` **desvía** de "dinero = `DECIMAL(12,2)`" (§7) | Aceptar la desviación, acotada a costos unitarios, escrita en §7 |
| **P4** | ¿`articles.current_unit_cost` como proyección, o derivar siempre de la última fila? | Proyección, con el historial como verdad y comando de reconstrucción |
| **P5** | ¿Los costos calculados en cascada se escriben en `article_costs`, que D14 define como "costo de adquisición"? | Sí, distinguidos por `origin` |
| **P6** | Override de markup: ¿sólo por artículo, o también por categoría? | Sólo artículo ahora; categoría diferida con deuda declarada |
| **P7** | ¿Tasa de IVA por artículo? §6.1 la define por tenant con override por sucursal | **No** por artículo en v1; es el riesgo que quiero que veas |
| **P8** | ¿Las reglas de un grupo de modificadores se pueden sobrescribir por artículo? | No |
| **P9** | Ventanas de disponibilidad ("menú por horario", §6.1) | Diferir a la Iteración 4 |
| **P10** | ¿`articles.code` (SKU) obligatorio u opcional? | Opcional |
| **P11** | ¿Categoría obligatoria para artículos vendibles? | Sí para vendibles, opcional para el resto |
| **P12** | Conversión de unidades: factor a base fija vs tabla de pares | Factor a base fija |
| **P13** | Ajuste nuevo `pricing.stale_price_tolerance_percent` | Crearlo |
| **P14** | ¿`modifiers.extra_price` puede ser negativo? | No; un modificador que resta es un descuento y ésos tienen su propio permiso y auditoría |

---

### P1 — ¿Cómo se declara y se impone que `Costing` dependa de `Catalog`?

`Costing` no puede existir sin leer artículos: `recipe_lines.component_article_id` es una FK a
`articles`. ARQUITECTURA_MAESTRA §2 dice "las dependencias fluyen hacia abajo; nunca laterales
directas entre módulos operativos", y los dos están **en la misma celda** del diagrama, así que la
regla no resuelve el caso. La regla 5 sí apunta a la salida: "cada módulo expone servicios públicos".

| | A favor | En contra |
|---|---|---|
| **A. Dependencia declarada e impuesta** (recomendada) | La FK ya existe de todos modos; el acoplamiento queda **explícito y verificado** en lugar de tácito; separa dos responsabilidades muy distintas (qué se vende / cuánto cuesta) | Hay que extender el registro y el candado de fronteras |
| **B. Fusionar en un solo módulo `Catalog`** | Cero pregunta de frontera | Contradice el registro y §14, que los nombran por separado; mete el motor de costeo, la detección de ciclos y el recálculo en cascada en el mismo módulo que el CRUD de etiquetas |
| **C. Permitirlo sin declararlo (statu quo)** | Nada que construir | Es lo que hay hoy y es exactamente cómo un monolito modular deja de serlo: dos módulos pueden acoplarse en **ambos** sentidos y ninguna prueba protesta |

**Recomiendo A.** Y con una consecuencia que conviene aceptar de una vez: el registro de módulos
gana `depends_on`, y el candado de fronteras pasa de vigilar sólo al kernel a imponer el grafo
completo. Es trabajo de una tarde que protege las nueve iteraciones que faltan.

### P2 — Unicidad cuando una columna de la llave puede ser `NULL`

Aparece dos veces en esta iteración (nombre de categoría por padre; y aparecería en precios si
tuvieran dimensión de canal) y ya apareció una vez en la Iteración 1, donde **D78 lo resolvió con dos
tablas**. En MySQL un índice único **no** deduplica `NULL`: `unique(tenant, parent_id, name)` permite
dos categorías raíz llamadas "Bebidas".

| | A favor | En contra |
|---|---|---|
| **A. Columna generada `STORED`** — `parent_key = COALESCE(parent_id, 0)`, `unique(tenant, parent_key, name)` (recomendada) | Una tabla, FK real sobre `parent_id`, unicidad **estructural**; es MySQL 8 nativo, no un truco de aplicación | Sería el primer uso del patrón en el proyecto; deja a D78 como el único lugar con la solución vieja |
| **B. Dos tablas** (como D78) | Consistente con lo que ya hay | Duplica CRUD y obliga a `articles` a dos FK nullable con CHECK, justo donde más duele |
| **C. Validación sólo en aplicación** | Nada que construir | Un nombre duplicado no rompe integridad, pero sí rompe la confianza en la pantalla; y una condición de carrera lo mete igual |

**Recomiendo A**, y con honestidad sobre el costo: adoptarla deja D78 como la única tabla con el
enfoque anterior. **No** propongo tocar D78 en esta iteración —cambiar configuración que ya funciona
para ganar consistencia estética no lo vale— pero sí que quede escrito que si la configuración se
vuelve a tocar, migra a este patrón.

### P3 — El costo unitario necesita cuatro decimales, y §7 dice dos

§7 fija "Dinero: `DECIMAL(12,2)`". Un costo unitario **no es un monto**, es un monto **por unidad**:
el gramo de sal cuesta $0.000012. A dos decimales es cero, y toda receta que use sal costaría cero.

| | A favor | En contra |
|---|---|---|
| **A. `DECIMAL(12,4)` para costos unitarios** (recomendada) | Resuelve el caso real; 4 decimales es lo que §7 ya usa para cantidades | Desvía de la convención y hay que escribirlo en §7 para que nadie lo "corrija" |
| **B. Mantener `(12,2)`** | Cero desviación | Los insumos baratos por unidad pequeña costarían cero. Es inaceptable |
| **C. Guardar el costo por presentación** ($600 el costal) y dividir al usar | Sin desviación | Traslada el redondeo a cada lectura y hace incomparables dos costos capturados con presentaciones distintas |

**Recomiendo A**, acotada: los **montos** siguen en `(12,2)`; sólo `unit_cost`, `suggested_price` y
las cantidades intermedias del costeo van a `(12,4)`. Si lo apruebas, actualizo §7 de
ARQUITECTURA_MAESTRA en la misma entrega, porque una convención con una excepción no escrita es una
convención que alguien va a romper de buena fe.

### P4 — ¿El costo vigente se proyecta o se deriva?

| | A favor | En contra |
|---|---|---|
| **A. Proyección en `articles`** (recomendada) | Costear un platillo con sub-recetas pasa de N consultas anidadas a una; hay **precedente explícito** en §6.2 ("kardex es la verdad, existencia es el acumulado") | Puede divergir del historial si un camino de escritura se salta la actualización |
| **B. Derivar siempre** | Imposible divergir | Cada pantalla de catálogo con costo hace una subconsulta por fila; el desglose de una receta anidada, muchas más |

**Recomiendo A**, con tres condiciones que forman parte de la decisión: la escritura de
`article_costs` y la actualización de la proyección ocurren en **la misma transacción**; existe
`php artisan comandia:costs:rebuild` que reconstruye la proyección desde el historial; y hay una
prueba que compara proyección contra historial para todo el catálogo y falla si divergen.

### P5 — ¿Los costos calculados van al historial de "costos de adquisición"?

D14 dice "costo vigente = último costo **de adquisición**". Un platillo no se adquiere.

Recomiendo **una sola tabla con `origin`**: el usuario quiere una pantalla de "cómo evolucionó el
costo de mis enchiladas", y con dos tablas habría que unirlas en cada lectura para siempre. La
alternativa —`recipe_costs` aparte— sólo compra pureza de vocabulario. Lo que **sí** hay que
respetar es que el promedio del periodo de D14 se calcule **sólo sobre `origin` de adquisición**:
promediar costos calculados con costos de compra mezcla dos cosas distintas.

### P6 — Override de markup: ¿artículo, o también categoría?

Un negocio quiere markup 250 % en bebidas y 180 % en alimentos; eso es por categoría. Pero
categoría-con-subcategoría convierte la cascada en cuatro niveles (artículo → subcategoría →
categoría → ajuste del tenant).

**Recomiendo sólo artículo ahora**, con la categoría diferida y declarada. Razón: el precio es
**sugerido** y el humano decide (D15), así que un default ausente cuesta una edición más por
artículo, no un número equivocado. Prefiero no estrenar una cascada de cuatro niveles en la primera
iteración que la usaría. Si me dices que el uso real lo exige desde el día uno, la incluyo — pero
entonces es una decisión tomada a sabiendas, no un descubrimiento tardío.

### P7 — ¿Tasa de IVA por artículo? — el riesgo que quiero que veas

§6.1 y D30 definen la tasa **por tenant con override por sucursal**, y así está implementada desde
la Iteración 1. Eso funciona para una fonda: todo lo que vende es alimento preparado al 16 %.

**Deja de funcionar** para un negocio mixto: una cafetería que vende café preparado (16 %) y también
bolsas de café en grano para llevar, o abarrotes, que en México llevan tasa 0 %. Ese tenant
facturaría mal, y "facturar mal" en México es un problema fiscal, no una molestia de UI.

No lo agrego por mi cuenta porque sería **inventar una regla de negocio crítica** que los documentos
maestros deciden en el otro sentido. Las opciones:

| | Consecuencia |
|---|---|
| **A. No hacer nada** (recomendada para v1) | Se respeta D30. El arquetipo es restaurante/cafetería (D2), no abarrotes. Riesgo documentado y acotado |
| **B. Agregar `articles.tax_rate` nullable** (NULL = hereda) | Cascada de tres niveles limpia, migración pequeña ahora y grande después. Contradice D30, así que exige **decisión registrada** |

**Recomiendo A y que quede en el registro de riesgos**, porque si aparece el primer tenant mixto,
B es una migración aditiva de una columna nullable y no un rediseño. Pero es tu decisión de producto,
no mía: dime si el mercado que buscas incluye negocios con tasa mixta.

### P8 — ¿Reglas de modificadores sobrescribibles por artículo?

**Recomiendo no.** Un artículo con reglas distintas usa un grupo distinto. Sobrescribir crea una
cascada en la validación más caliente del POS ("¿puedo comandar esto?"), y ahí una regla ambigua no
es un bug de UI: es un platillo mal preparado y un cliente esperando.

### P9 — Ventanas de disponibilidad por horario

Es requisito de §6.1 ("desayunos hasta 13:00, menú del día entre semana"). **Recomiendo diferirlo a
la Iteración 4** y decirlo como deuda declarada: su único consumidor es el POS, la regla vive junto
al modelo de menú que el POS define, y diseñar las ventanas sin ese modelo es diseñarlas para
rehacerlas. Lo alternativo —crear la tabla ahora y dejarla sin nadie que la aplique— es peor: da la
apariencia de que la capacidad existe.

### P10, P11, P14 — tres decisiones pequeñas

- **P10 `code` opcional.** Un restaurante no le pone SKU a "Enchiladas suizas". Obligarlo produce
  códigos inventados que nadie usa. Único por tenant cuando está presente.
- **P11 categoría obligatoria para vendibles.** El POS agrupa por categoría; un artículo vendible sin
  categoría no tiene dónde aparecer en pantalla. Para insumos es opcional: la harina no necesita
  categoría de venta.
- **P14 `extra_price >= 0`.** Un modificador que resta es un descuento, y los descuentos tienen
  permiso, motivo y actor propios (§6.3, zona de máxima auditoría). Permitirlos aquí sería una puerta
  para descontar sin dejar rastro.

### P12 — Conversión por factor a base fija

Ya argumentado en §2.1: con una tabla de pares, dos filas pueden contradecirse (kg→lb = 2.2 y
lb→kg = 0.45) y nada lo detecta; con factor a base fija, la contradicción es imposible por
construcción. **Recomiendo factor a base fija.** El costo es que no hay conversión entre dimensiones,
y ése es un requisito, no una limitación: convertir piezas a kilos sólo es cierto por artículo, y eso
son las presentaciones de compra.

### P13 — El ajuste de tolerancia del semáforo

Ya justificado en §5. Sin umbral, el redondeo que el propio tenant configuró marca todo el catálogo
como desactualizado, y un semáforo permanentemente en rojo se deja de mirar en dos días — con lo que
se pierde la señal que D15 quería.

---

## 12. Resumen: 14 tablas nuevas

| Módulo | Tabla | Naturaleza |
|---|---|---|
| Catalog | `units` | catálogo |
| Catalog | `article_categories` | catálogo, 2 niveles con CHECK |
| Catalog | `tags` · `article_tag` | catálogo + pivote |
| Catalog | `articles` | **núcleo de la iteración** |
| Catalog | `article_branch_overrides` | cascada de 2 niveles |
| Catalog | `article_purchase_presentations` | catálogo por artículo |
| Catalog | `price_changes` | **inmutable** |
| Catalog | `modifier_groups` · `modifiers` · `article_modifier_group` | catálogo + pivote |
| Costing | `recipes` | cabecera, dueño artículo XOR modificador |
| Costing | `recipe_lines` | grafo de composición |
| Costing | `article_costs` | **inmutable** |

Dos tablas inmutables nuevas, que suman a las seis que §7 declara. Cero excepciones nuevas a la
Regla A. Un ajuste de configuración nuevo. Ningún permiso nuevo.

---

**Esperando tu decisión sobre P1 a P14.** Nada se implementa antes. Si prefieres, podemos resolver
sólo las tres que cambian el esquema (P1, P2, P3) y avanzar con los pasos 1 a 4 del orden de
implementación mientras decides el resto — pero P4 entra en el paso 4, así que en la práctica las
cuatro primeras son el bloque mínimo.
