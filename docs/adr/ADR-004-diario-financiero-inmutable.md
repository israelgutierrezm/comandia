# ADR-004 — Finanzas: diario inmutable tipado con documento origen; cortes calculados

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 5 (Finanzas) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §6, §7 y §13, y ESPECIFICACION_MAESTRA §6.5.

---

## Decisión

El diario financiero es **append-only**: sin `UPDATE` ni `DELETE`. Todo movimiento
está tipado, tiene documento origen y actor, y toda corrección es un **movimiento de
reversa enlazado al original**. Los cortes de caja se **calculan** del diario y
nunca se almacenan como verdad paralela.

---

## Contexto

- El corte de caja que no cuadra es uno de los riesgos principales del producto
  (ESPECIFICACION_MAESTRA §9).
- El "robo hormiga" —descuentos, cortesías y cancelaciones usadas para desviar
  efectivo— es el fraude característico del sector y sólo se detecta si cada
  movimiento tiene actor y motivo (ESPECIFICACION_MAESTRA §6.3).
- Los movimientos del diario los produce el POS, e-commerce, gastos, retiros,
  depósitos, propinas y crédito a clientes: **muchos productores, un solo libro**.
- Las consecuencias financieras se procesan por colas y pueden llegar con segundos
  de retraso (ARQUITECTURA_MAESTRA §6).
- Sesión de caja: apertura con fondo → operación → precorte ciego → corte
  (esperado vs. declarado por método) → cierre (ESPECIFICACION_MAESTRA §6.3).

---

## Problema

Si el diario admite `UPDATE`, la pregunta "¿cuánto se vendió el martes?" deja de
tener una respuesta única: tiene la respuesta de hoy. Un movimiento corregido borra
la evidencia de que existió el error, que es justo la evidencia que se necesita
para detectar un desvío.

Si además el corte se **almacena** como total calculado en su momento, aparece una
segunda fuente de verdad. En cuanto un movimiento llega tarde por la cola, o se
reversa un pago después del cierre, el corte guardado y el diario dicen cosas
distintas. Y no hay forma de saber cuál miente.

---

## Alternativas

### A. Tablas financieras mutables (CRUD convencional)
- **Qué implica:** movimientos editables y borrables; corrección editando.
- **A favor:** familiar; corregir un error de captura es un `UPDATE`.
- **En contra:** destruye la trazabilidad; hace indetectable el fraude por
  corrección; imposibilita reconstruir el estado a una fecha. Incompatible con el
  riesgo principal del producto.

### B. Diario append-only tipado + cortes calculados
- **Qué implica:** sin `UPDATE`/`DELETE`; corrección por reversa enlazada; el corte
  es una consulta, no un registro.
- **A favor:** el corte cuadra **por construcción**; auditoría completa gratis; se
  puede reconstruir cualquier estado a cualquier fecha; una reversa es evidencia,
  no borrado.
- **En contra:** más filas; los cortes se recalculan en cada consulta; corregir un
  error de captura exige dos movimientos y entender el concepto de reversa.

### C. Event sourcing formal
- **Qué implica:** el estado del sistema entero como flujo de eventos con
  proyecciones reconstruibles.
- **A favor:** todo lo de B, generalizado a todos los dominios.
- **En contra:** complejidad conceptual y operativa alta —versionado de eventos,
  reproyecciones, snapshots— para un equipo pequeño. Y **está prohibido por
  ADR-001** sin ADR que lo autorice. B da el 90% del beneficio donde importa —el
  dinero— sin pagar el 100% del costo en todos los módulos.

---

## Decisión tomada

**Alternativa B.**

Todo movimiento del diario registra: **tipo** (de catálogo cerrado), **documento
origen**, tenant, sucursal, sesión de caja, método de pago, bandera *afecta cajón* y
**actor**.

- Los módulos generan movimientos **vía eventos de dominio**. Nadie escribe en
  finanzas directamente —ni el POS, ni e-commerce—.
- **Los cortes se calculan** del diario. La diferencia entre esperado y declarado es
  a su vez un movimiento tipado, no un campo de ajuste.
- Al cerrar una sesión de caja se **verifica el drenado de la cola** de esa sesión
  antes de calcular el corte: el diario es una proyección confiable con retraso de
  segundos, y el corte no puede leerla a medias
  (ARQUITECTURA_MAESTRA §6).
- Los jobs que generan movimientos son **idempotentes obligatoriamente**, con llave
  de idempotencia por documento origen más tipo: reentregar un evento **nunca**
  duplica un movimiento.

Inmutables por la misma lógica, según ARQUITECTURA_MAESTRA §7: diario financiero,
kardex, historial de precios, historial de costos, bitácora de auditoría y **pagos**.

---

## Justificación

La correctitud es la prioridad número uno del proyecto y en el módulo financiero la
correctitud **es** la inmutabilidad: un libro que se puede editar no es un libro, es
un borrador. El costo —más filas y cortes recalculados— es de rendimiento, que es
la penúltima prioridad de la lista.

"Cortes calculados" es la parte de la decisión que más se suele negociar y la que no
debe negociarse: en el momento en que un total se guarda como verdad, el sistema
adquiere dos respuestas para la misma pregunta y pierde la capacidad de saber cuál
es la correcta.

---

## Consecuencias

**Se gana**
- Cortes que cuadran por construcción, no por conciliación.
- Auditoría financiera completa sin esfuerzo adicional.
- Detección de robo hormiga: cada descuento, cortesía y cancelación tiene actor,
  motivo y momento.
- Capacidad de reconstruir el estado financiero a cualquier fecha.

**Se paga**
- Volumen: el diario es una tabla de alto volumen desde el día uno, con
  particionamiento lógico por fecha previsto como evolución.
- Los cortes son consultas agregadas; requieren índices bien elegidos.
- Costo conceptual: el equipo y el usuario avanzado tienen que entender que aquí no
  se corrige, se reversa.

**Reglas que quedan vigentes**
1. Cero `UPDATE` y cero `DELETE` sobre el diario. Corrección **sólo** por reversa
   enlazada al movimiento original.
2. Ningún módulo escribe en finanzas. Sólo listeners de eventos de dominio.
3. Ningún corte se persiste como total. Se calcula.
4. Todo job financiero es idempotente, con llave por documento origen + tipo.
   *(Test de idempotencia obligatorio en la definition of done.)*
5. Tests de invariantes financieras obligatorios: el diario de una sesión suma el
   corte; toda reversa enlaza a su original; una cuenta pagada suma pagos = total.
6. El tipo de movimiento sale de un catálogo cerrado. No hay movimientos "otros".

**Puerta de salida**
Si el volumen hiciera inviable calcular cortes en línea, la salida son **agregados
materializados** —previstos como evolución en ESPECIFICACION_MAESTRA §5— con el
diario intacto como fuente de verdad. Un agregado es una cache reconstruible, nunca
una verdad paralela: esa distinción es la que esta ADR protege.
