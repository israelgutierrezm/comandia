# ADR-001 — Monolito modular con fronteras por eventos: extraíble, no distribuido

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 0 (transversal a todas) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §1, §2 y §13. El texto normativo es el del
> documento maestro; aquí se expande el razonamiento y las consecuencias
> operativas para poder auditar la decisión en cada iteración.

---

## Decisión

Comandia es **una sola aplicación Laravel** con módulos como fronteras lógicas
disciplinadas —namespaces y carpetas—, no paquetes Composer ni repositorios
separados; los efectos cruzados entre módulos viajan por eventos de dominio.

---

## Contexto

- Producto nuevo, sin cliente ancla, con necesidad de salir a operación real
  temprano (D1).
- Escala de diseño del año 1: decenas de tenants, de 1 a 5 sucursales cada uno,
  pico de 500–1,000 cuentas/día en el tenant más pesado
  (ESPECIFICACION_MAESTRA §2).
- Infraestructura v1: **un solo VPS** con Nginx, PHP-FPM, MySQL, Redis, workers y
  Reverb (ARQUITECTURA_MAESTRA §12).
- Superficie funcional amplia desde v1: 17 módulos núcleo más 2 activables.
- El módulo financiero exige invariantes contables verificables: un corte debe
  cuadrar contra el diario **por construcción** (ADR-004).

---

## Problema

Una superficie funcional de este tamaño se degrada de dos maneras opuestas y las
dos son fatales:

1. **Sin fronteras:** el POS escribe directo en inventarios y en finanzas, y a los
   seis meses ninguna regla de negocio tiene un dueño identificable. Cambiar el
   costeo obliga a leer el POS.
2. **Con fronteras físicas prematuras** (microservicios o paquetes separados): se
   paga latencia de red, consistencia eventual, despliegue coordinado y
   observabilidad distribuida —para decenas de tenants en un solo VPS— antes de
   tener un cliente pagando.

---

## Alternativas

### A. Monolito sin módulos (Laravel estándar por capas técnicas)
- **Qué implica:** `app/Models`, `app/Http/Controllers`, `app/Services` planos.
- **A favor:** cero ceremonia, arranque inmediato, cualquier desarrollador
  Laravel lo entiende sin explicación.
- **En contra:** las reglas de negocio de costeo, POS y finanzas terminan
  mezcladas en servicios compartidos; el aislamiento entre dominios depende de la
  memoria del equipo. No hay ningún punto donde un test pueda decir "esto no
  debería estar aquí".

### B. Monolito modular con fronteras lógicas y eventos de dominio
- **Qué implica:** `app/Modules/{Modulo}/` con capas internas; comunicación
  cruzada por eventos; la disciplina la imponen convenciones y tests, no la
  infraestructura.
- **A favor:** una sola base de datos y una sola transacción cuando se necesita;
  despliegue trivial; fronteras auditables; los módulos con frontera limpia
  (e-commerce, reportes, impresión) quedan extraíbles si el crecimiento lo exige.
- **En contra:** la frontera es convención, no muro: un desarrollador con prisa
  puede cruzarla con un `use`. Requiere vigilancia activa en revisión y tests
  estructurales.

### C. Paquetes Composer independientes en el mismo repositorio
- **Qué implica:** cada módulo como paquete con su `composer.json`.
- **A favor:** la frontera sí es física; el autoloader la impone.
- **En contra:** versionado interno, `composer update` como ritual diario, y
  fricción alta para el caso más común del proyecto —un cambio que toca catálogo,
  costeo y POS a la vez—. Coste de mantenimiento desproporcionado al tamaño del
  equipo.

### D. Microservicios
- **Qué implica:** servicios desplegables por separado con API entre ellos.
- **A favor:** escalamiento y despliegue independientes.
- **En contra:** consistencia eventual obligatoria justo donde el producto exige
  invariantes contables; latencia de red en el camino crítico del POS;
  observabilidad distribuida y orquestación para un solo VPS. Coste
  desproporcionado a la escala real.

---

## Decisión tomada

**Alternativa B.** Una aplicación Laravel, módulos como fronteras lógicas en
`app/Modules/{Modulo}/`, con estructura interna fija (`Domain`, `Application`,
`Infrastructure`, `Http`, `Events`, `Listeners`, `Jobs`, `database`).

Queda **prohibido sin ADR nueva que reemplace esta**: microservicios, event
sourcing, CQRS formal, Elasticsearch, Kafka y Kubernetes.

El nivel de formalidad DDD es pragmático: entidades y servicios claros, sin
aggregates formales ni repositorios abstractos donde Eloquent basta. **La frontera
es sagrada; el interior es práctico.**

---

## Justificación

La prioridad número uno del proyecto es la **correctitud**
(ESPECIFICACION_MAESTRA §1), y la correctitud aquí es contable: el diario tiene
que cuadrar, el kardex tiene que reconstruir la existencia, un pago no puede
duplicarse. Las alternativas distribuidas convierten cada una de esas invariantes
en un problema de consistencia entre sistemas; el monolito las deja resolubles con
una transacción de base de datos y un job idempotente.

La **mantenibilidad** —tercera prioridad— es lo que compra la modularidad, y se
compra sin pagar la escalabilidad distribuida que la escala real no pide todavía.

---

## Consecuencias

**Se gana**
- Una transacción de base de datos abarca lo que el negocio considera atómico.
- Despliegue de un solo artefacto; una ventana de mantenimiento, no un baile de
  versiones entre servicios.
- Fronteras revisables: el mapa de dependencias es un archivo de configuración,
  no una topología de red.
- Puerta de salida real: un módulo con frontera limpia puede extraerse el día que
  su volumen lo justifique.

**Se paga**
- La frontera no la impone la infraestructura. Se erosiona en silencio si nadie la
  vigila.
- Un módulo mal escrito puede degradar el rendimiento de todo el proceso.
- Escalamiento inicialmente vertical.

**Reglas que quedan vigentes**
1. Todo módulo puede depender del shared kernel. El kernel no depende de ningún
   módulo de dominio. *(Verificado por `tests/Architecture/ModuleBoundariesTest.php`.)*
2. Las dependencias fluyen hacia abajo en el mapa de ARQUITECTURA_MAESTRA §2;
   nunca hacia arriba ni lateralmente entre módulos operativos.
3. Los efectos colaterales cruzados viajan por eventos de dominio, nunca por
   escritura directa: **el POS no escribe en finanzas ni en inventarios.**
4. Cada módulo expone servicios de `Application/`, sus eventos y sus listeners.
   Todo lo demás es privado del módulo.
5. Los módulos activables consultan el Core por sus servicios públicos; el Core
   ignora su existencia.

**Puerta de salida**
Extraer un módulo a servicio propio. La señal que lo justificaría: un módulo cuyo
perfil de carga sea tan distinto al del resto que comparta VPS lo perjudique
—candidatos naturales: reportes pesados, impresión, e-commerce—. La extracción
consiste en publicar sus eventos por un transporte remoto y darle base propia;
requiere ADR nueva que reemplace esta.
