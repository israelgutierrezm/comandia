# Iteración 2 — Revisión de cierre

**Catálogo + Recetas/Costeo.** Doce pasos en diez commits —los cuatro primeros entraron juntos— más el
cierre de los huecos del kernel. La suite pasó de **321 a 566** pruebas.

Este documento no repite el diseño —eso está en `ITERACION_2_DISENO.md`— ni el registro de decisiones.
Contesta tres preguntas: **qué quedó construido**, **qué salió mal y qué se aprendió**, y **qué se lleva
la Iteración 3**.

---

## 1. Qué quedó construido

| Módulo | Tablas | Lo que resuelve |
|---|---|---|
| `Catalog` | 8 | Artículo unificado con cuatro capacidades (D17), unidades y conversiones, categorías de dos niveles, etiquetas, presentaciones de compra, historial inmutable de precios, overrides por sucursal, modificadores con reglas |
| `Costing` | 4 | Historial inmutable de costos, proyección del costo vigente, recetas con detección de ciclos, motor de costeo en cascada, precio sugerido con semáforo |

Catorce tablas, todas con `tenant_id` NOT NULL y global scope; dos de ellas append-only y verificadas por
el candado de §7. Setenta y tantas rutas de `/api/v1`, todas con permiso declarado y verificado.

Lo que hace interesante la iteración no es el CRUD: es que **el costo de un platillo se calcula solo y en
cascada**, atravesando recetas anidadas, convirtiendo unidades y aplicando rendimientos de insumo, con el
recálculo en cola y sin bloquear a nadie. Y que **el sistema sugiere el precio y la persona decide**, con
cada cambio historizado junto al estado del costeo que lo justificaba.

---

## 2. Lo que salió mal

**Diecisiete defectos** encontrados con la suite en verde, más uno que se detectó leyendo el modelo antes
de abrir el navegador. Ocho al construir la UI de catálogo, cinco al cerrar los huecos del kernel y cuatro
en la auditoría de endpoints sin llamar; **seis** de ellos eran de la Iteración 1.

Clasificarlos dice más que enumerarlos, porque el patrón se repite y se puede atacar.

### 2.1 Defectos que la suite no podía ver por su naturaleza

Cinco, todos de interfaz: las pestañas apareciendo escalonadas (D138), el buscador dejando resultados de
otra búsqueda (D137), el factor con ocho ceros y la etiqueta duplicada en el cliente (D139), y el campo
que prometía asignar un código de empleado que el servidor no genera (D141).

Las pruebas no montan Vue, así que ninguna prueba razonable los habría encontrado. **La única defensa es
abrir el navegador**, y esta vez se abrió desde el principio en lugar de al final.

El que no cuenta aquí es el decimocuarto: la UI leía `capabilities.is_sellable` cuando el servidor manda
`capabilities.sellable`, lo que habría dejado **todo artículo** marcado como «no se vende». Se detectó
leyendo el modelo antes de abrir el navegador, y por eso no llegó a ser un defecto — pero es la misma
clase: un contrato entre cliente y API que nada verifica.

### 2.2 Defectos que la suite no vio porque le faltaba un DATO

El más incómodo, y el que dejó dos candados:

- **Buscar con acentos devolvía 500** en siete listados, desde la Iteración 1 (D135). Todas las pruebas
  buscaban palabras sin acentos. No faltaba una prueba: faltaba una `ú` en las que ya existían.
- **El mismo importe con dos valores distintos** en la misma pantalla (D134). Las pruebas comparaban el
  resultado contra el que produce el mismo motor, así que coincidían siempre.

Lección: una suite que genera sus propios datos de prueba comparte los sesgos de quien la escribió. Los
datos verosímiles del negocio de demostración (D133) son parte de la defensa, no un adorno para demos.

### 2.3 Defectos que la suite no vio porque el endpoint NO SE HABÍA LLAMADO NUNCA

Cinco, y son los más graves. Cuatro salieron de la auditoría de cierre —los diecinueve endpoints huérfanos
de §5— y el quinto es el permiso sin ruta:

- **`GET` y `PUT` del perfil laboral respondían 500 desde la Iteración 1** (D144). Había prueba del
  `DELETE`, que devuelve 204 y no pasa por el recurso.
- **La cantidad de una presentación se podía cambiar** (D147), y es el divisor de los costos ya capturados.
- **Una ruta anidada no verificaba la relación que afirma** (D148): la presentación del queso se podía
  editar a través de la URL del jitomate, con respuesta 200.
- **Dos endpoints devolvían decimales con distinta escala que sus lecturas** (D149).
- **Un permiso del catálogo llevaba una iteración sin ruta** (D140): se podía conceder y no hacía nada.

Lección, y es la más simple de todas: **la cobertura por módulo no ve un endpoint sin llamar**, porque un
módulo con veinte pruebas y tres rutas huérfanas se ve igual de sano que uno completo. Ya es candado
(D146). Lo del permiso sin ruta no se puede convertir en candado todavía —el catálogo declara permisos de
iteraciones futuras a propósito— pero sí en pregunta obligatoria de cierre.

### 2.4 Defectos de estado que sólo aparecen usando el sistema de verdad

- **Un negocio inservible en la sesión encerraba a la persona** (D142), sin poder entrar a otro ni cerrar
  sesión. Apareció al re-sembrar el negocio de demostración con la sesión abierta — que es exactamente lo
  que le pasa a un cliente al que se le suspende la cuenta con la pestaña abierta.
- **La bitácora registraba consultas de datos sensibles que nadie hizo** (D141), porque la ficha pedía el
  perfil al montar. Un registro de accesos a datos personales lleno de consultas que no ocurrieron diluye
  las que sí.

### 2.5 Un defecto que se cometió DOS veces

El recuadro de error rojo vacío y permanente (D136): treinta y cinco veces, nueve pantallas de la
Iteración 1 y seis de la 2. La forma equivocada —`save.generalError` sin `.value`— es la que se escribe
sola y funciona «casi». De ahí el candado: convertir un error silencioso en una prueba roja es la única
manera de que no vuelva.

---

## 3. Candados nuevos

Seis, y todos verificados **rompiendo el arreglo a propósito** para comprobar que muerden:

| Candado | Qué impide |
|---|---|
| `RoutePermissionTest` | Endpoints abiertos y permisos mal escritos (D129) |
| `ImmutableTablesTest` | Una tabla de §7 sin el trait, y un trait sin tabla en §7 (D130) |
| `CatalogAuthorizationMatrixTest` | Un permiso nuevo sin reparto verificado (D128) |
| `AccentedSearchTest` | El 500 de la búsqueda con acentos, en los listados de hoy y de mañana (D135) |
| `FrontendRefUnwrapTest` | Los refs de composables leídos sin `.value` (D136) |
| `EveryEndpointIsExercisedTest` | Un endpoint que ninguna prueba llama nunca (D146) |

Más los que ya existían y **hicieron su trabajo** en esta iteración: el de fronteras de módulos rechazó
una dependencia inversa, el de etiquetas de auditoría atrapó una acción nueva sin traducir, y el de
`withoutGlobalScopes` atrapó una excepción de aislamiento sin declarar. Un candado que nunca falla es un
candado que no se sabe si funciona.

---

## 4. Deuda pagada y deuda nueva

**Pagada:** D100 (las recetas de modificador exigían una columna que la migración del paso 5 no tenía;
se pagó en el paso 10 con `200540_add_modifier_owner_to_recipes_table`). Los tres huecos de la UI del
kernel. Y dos defectos de la Iteración 1 que nadie sabía que existían (D135, D144).

**Nueva:** ninguna simplificación silenciosa. Lo que no entró está declarado en §8 del diseño y sigue
declarado.

---

## 5. Lo que la Iteración 3 se lleva

### Decisión de producto pendiente — **P7, tasa de IVA por artículo**

Es la única decisión abierta de esta iteración, y **no la tomo yo**: es fiscal.

El diseño deja la tasa por tenant con override por sucursal (§6.1). Basta para un negocio de tasa única y
**es incorrecto para uno de tasas mixtas** — alimentos preparados al 16 % y despensa al 0 % en la misma
cuenta. Si aparece un cliente así, el desglose de IVA de todos sus documentos ya emitidos queda mal, y eso
no se corrige con una migración: son documentos fiscales.

- **Mi recomendación:** no hacerlo en v1 y dejar el riesgo registrado.
- **La alternativa:** agregar la tasa por artículo ahora — una columna, un poco de UI y validación —
  porque cuesta menos hoy que una corrección imposible después.

La decisión hay que tomarla antes de emitir el primer documento fiscal, o sea antes de la Iteración 7.

### Cambio de diseño que exige aprobación — **`auditable_ulid`**

Guardar el ULID de la entidad auditada en el propio asiento. Es lo correcto —la bitácora es evidencia y
debe ser autocontenida aunque la fila original desaparezca— y resolverlo por fila sería una consulta por
fila sobre una tabla de alto volumen. Pero es **una columna nueva en una tabla inmutable**, o sea un cambio
del diseño del kernel: exige aprobación explícita antes de escribir la migración.

### Revisión de cierre: ya se hizo, y encontró cuatro defectos más

**Pregunta 1: ¿qué endpoints no se han llamado nunca?** Diecinueve de ciento uno. Al llamarlos por primera
vez salieron cuatro defectos, dos de ellos vivos desde la Iteración 1:

| Endpoint | Lo que apareció |
|---|---|
| `GET`/`PUT` del perfil laboral | **500 desde la Iteración 1** (D144) |
| `POST /authorizations` | El endpoint del PIN —el único sin permiso y el único con límite de intentos— sin una sola prueba HTTP |
| `PATCH` de presentación | La **cantidad se podía cambiar**, y es el divisor de los costos ya capturados (D147) |
| `PATCH`/archivar presentación | La ruta anidada **no verificaba** que la presentación fuera del artículo de la URL: respondía 200 (D148) |
| `POST /units`, `POST` de modificador | Devolvían decimales con distinta escala que sus propias lecturas (D149) |

Ya está convertido en **candado** (D146): toda ruta de `/api/v1` tiene que aparecer en al menos una
prueba. Ahora son 101 de 101.

**Pregunta 2: ¿qué permisos no tienen ruta?** Uno: `identity.memberships.manage_branch_scopes`, que llevaba
una iteración entera pudiéndose conceder sin hacer nada (D140). Esto **no** se puede convertir en candado
todavía —el catálogo declara a propósito permisos de iteraciones que no existen— así que queda como
pregunta obligatoria al cerrar cada iteración, acotada a los módulos ya construidos.

### Dependencias que la Iteración 3 hereda

- El **kardex** y el **diario financiero** entran en la lista de tablas inmutables de §7 al construirse; el
  candado de D130 es el recordatorio.
- El costeo por **promedio ponderado** de las entradas de inventario reemplazará la captura manual de costo
  como origen principal. `CostOrigin::Purchase` ya existe y no se usa: es el punto de conexión.
- El **POS nunca se bloquea por inventario** (§6): las existencias negativas están permitidas y el descuento
  es asíncrono. La cola `critical` y los jobs idempotentes de esta iteración son el patrón a seguir.
