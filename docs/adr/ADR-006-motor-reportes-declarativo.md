# ADR-006 — Motor de reportes declarativo: definiciones, endpoint genérico y export por colas

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 7 (Reportes + Dashboards + Notificaciones) — renumerada por D309 (antes «8») |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §8 y §13, y ESPECIFICACION_MAESTRA §6.7 (D45, D46).

---

## Decisión

Un reporte es una **definición declarativa** —dataset, columnas, filtros,
agrupaciones y permiso—; **un solo endpoint genérico** valida la petición contra esa
definición, aplica el scoping y ejecuta. El frontend se autoconfigura desde la
definición y los exports pesados salen por la cola `exports`.

---

## Contexto

- El producto necesita reportes en todos los módulos: ventas, cortes, inventario,
  mermas, costos, márgenes, descuentos, propinas, clientes, pedidos.
- Sobre el mismo motor se construye el **constructor de dashboards**: un widget es
  un reporte más una configuración de visualización (D46).
- Se exige **exportación PDF/Excel por colas** con notificación y **reportes
  programados** desde v1 (scheduler + correo + notificación).
- Cada reporte tiene su propio permiso, y todo dato debe respetar el scoping de
  tenant y el alcance de sucursales del rol activo.
- Regla de API vigente: filtros, orden y búsqueda con **whitelist por endpoint**,
  nunca filtros libres (ARQUITECTURA_MAESTRA §8).

---

## Problema

Escribir cada reporte como un endpoint propio produce dos problemas que crecen con
el catálogo. Primero, repetición: paginación, filtros, ordenamiento, scoping,
verificación de permiso, export y programación se reimplementan decenas de veces, y
cada reimplementación es una oportunidad de olvidar el filtro de tenant —el error
más caro del producto según ADR-002—.

Segundo, el frontend crece a la misma velocidad: una pantalla por reporte, con sus
propios controles de filtro escritos a mano.

Pero la solución obvia —"un endpoint que acepte la consulta que el cliente quiera"—
es peor: es un motor de consultas arbitrarias expuesto a internet, capaz de leer
cualquier tabla de cualquier tenant y de tumbar la base con un `GROUP BY` mal
pensado.

---

## Alternativas

### A. Un endpoint por reporte, escrito a mano
- **A favor:** control total y optimización individual; consultas legibles.
- **En contra:** repetición masiva de scoping, permisos, export y programación; el
  riesgo de aislamiento se multiplica por el número de reportes; el frontend crece
  linealmente.

### B. Motor declarativo con definiciones en código y endpoint genérico validado
- **A favor:** el scoping, el permiso, la whitelist de filtros, el export y la
  programación se implementan **una vez**; agregar un reporte es agregar una
  definición; el frontend se autoconfigura; la superficie de ataque es finita porque
  la definición es la whitelist.
- **En contra:** el motor es infraestructura que hay que construir antes del primer
  reporte; un reporte con necesidades muy particulares puede no encajar y requerir
  una salida de escape.

### C. Consultas libres desde el cliente (GraphQL o SQL parametrizado)
- **A favor:** flexibilidad máxima; cero trabajo por reporte nuevo.
- **En contra:** inaceptable. Rompe la Regla B de ADR-002 y la regla de whitelist de
  §8; expone rendimiento y datos a la creatividad del cliente.

---

## Decisión tomada

**Alternativa B.**

- Un reporte = **definición en código** con: dataset, columnas disponibles, filtros
  permitidos, agrupaciones permitidas y **permiso requerido**.
- **Un endpoint genérico** recibe el nombre del reporte más los parámetros, valida
  cada parámetro contra la definición —lo que no está declarado no existe—, aplica el
  scoping de tenant y de sucursales del rol activo, y ejecuta.
- El **frontend se autoconfigura** desde la definición: los controles de filtro y las
  columnas se derivan de ella, no se escriben por reporte.
- **Export PDF/Excel por la cola `exports`**, con notificación interna al terminar.
  Un export de un año de ventas no compite con un movimiento de diario: son colas
  distintas (ARQUITECTURA_MAESTRA §6).
- **Vistas guardadas** por usuario y **reportes programados** (scheduler + correo +
  notificación) desde v1.
- Los **dashboards** se construyen encima: widget = reporte + visualización (número,
  serie, barras, pastel, top-N, semáforo contra meta) + rango temporal con
  comparativo. **Los permisos del widget se heredan del reporte**, no se declaran
  aparte.

---

## Justificación

Esta decisión es, sobre todo, una decisión de **seguridad** disfrazada de decisión de
productividad. Centralizar el scoping en un solo camino de ejecución significa que el
aislamiento de tenant en reportes se audita **una vez** en lugar de una vez por
reporte. Con decenas de reportes, esa diferencia es la que hace que la Regla B de
ADR-002 sea sostenible.

La productividad viene de paso: la mantenibilidad es la tercera prioridad del
proyecto y el motor la compra con un costo inicial que se amortiza en el segundo
reporte.

---

## Consecuencias

**Se gana**
- Scoping, permisos, paginación, export y programación implementados una vez y
  auditados una vez.
- Agregar un reporte es agregar una definición, sin tocar frontend ni backend
  genérico.
- Dashboards y reportes programados salen casi gratis del mismo motor.

**Se paga**
- Hay que construir el motor antes del primer reporte.
- Un reporte con forma muy particular puede requerir una definición forzada o una
  salida de escape documentada.
- Las consultas generadas son menos afinables individualmente que una escrita a
  mano; los índices hay que planearlos desde el diseño del dataset.

**Reglas que quedan vigentes**
1. Ningún reporte se implementa como endpoint propio sin justificación escrita.
2. Lo que no está declarado en la definición **no se puede pedir**: cero filtros
   libres, cero columnas ad hoc.
3. Todo reporte declara su permiso. Los widgets de dashboard lo heredan.
4. El scoping de tenant y de sucursales lo aplica el motor, no la definición: una
   definición no puede desactivarlo.
5. Todo export pesado va a la cola `exports`, nunca en la petición del usuario.

**Puerta de salida**
Si el volumen exigiera más, la evolución prevista son **agregados materializados**
(ESPECIFICACION_MAESTRA §5) detrás del mismo endpoint: cambia el dataset, no el
contrato. Elasticsearch está **prohibido sin ADR** (ADR-001).
