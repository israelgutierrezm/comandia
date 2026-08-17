# ADR-002 — Multi-tenancy: base compartida con `tenant_id`, reglas A/B y puerta de salida

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 1 (Shared Kernel) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §3 y §13. **Es la ADR más crítica del
> proyecto**: es la única cuya violación produce un daño irreversible y
> jurídicamente grave —los datos de un negocio vistos desde otro—.

---

## Decisión

Una sola base de datos MySQL compartida por todos los tenants, con `tenant_id`
NOT NULL en toda tabla de dominio, scoping global obligatorio en todo modelo, y
diseño preparado para extraer un tenant a base dedicada sin rediseñar.

---

## Contexto

- Decenas de tenants el primer año, cada uno de 1 a 5 sucursales
  (ESPECIFICACION_MAESTRA §2).
- Un usuario global puede pertenecer a N tenants independientes
  (ESPECIFICACION_MAESTRA §4.1); el aislamiento no puede depender de la identidad
  del usuario.
- Existe un panel de super admin que **sí** agrega entre tenants, pero sólo
  metadatos y métricas, y sólo desde fuera del código de dominio
  (ESPECIFICACION_MAESTRA §2).
- Se prevé la necesidad futura de mover un tenant grande a base dedicada.
- Infraestructura v1: un solo VPS, un solo MySQL (ARQUITECTURA_MAESTRA §12).

---

## Problema

El aislamiento lógico —un `WHERE tenant_id = ?` que el desarrollador debe
recordar— es la fuente número uno de fuga de datos en un SaaS multi-tenant. Un
solo listado sin filtro, un solo `find()` por id sin acotar, un solo reporte con
un `JOIN` mal escrito, y un restaurante ve las ventas de su competidor.

Además, el peligro es **asimétrico**: una consulta sin scope no falla. Devuelve
datos. El error no se manifiesta como excepción, se manifiesta como información
de más en la pantalla de alguien.

---

## Alternativas

### A. Base de datos por tenant
- **Qué implica:** conexión dinámica por tenant; migraciones ejecutadas N veces.
- **A favor:** aislamiento por construcción; imposible una consulta cross-tenant
  por descuido; respaldo y restauración por tenant triviales.
- **En contra:** migrar 50 bases en cada despliegue; el panel de super admin
  necesita consultar N bases para cualquier métrica agregada; alta de tenant deja
  de ser un `INSERT` y se vuelve una operación de infraestructura; costo
  operativo desproporcionado al tamaño del equipo.

### B. Esquema por tenant (misma instancia, N esquemas)
- **Qué implica:** un esquema MySQL por tenant.
- **A favor:** aislamiento fuerte con una sola instancia de servidor.
- **En contra:** el soporte de Laravel/Eloquent para esto es artesanal; los mismos
  problemas de migración N veces que A; MySQL no distingue realmente base y
  esquema, así que se hereda la complejidad de A sin heredar sus ventajas
  operativas.

### C. Base compartida con `tenant_id` y scoping global disciplinado
- **Qué implica:** una migración, una conexión; el aislamiento se impone en el
  ORM y se verifica con tests.
- **A favor:** operación simple; alta de tenant es un `INSERT`; el super admin
  agrega con una consulta; escalar es escalar una base.
- **En contra:** el aislamiento depende de la disciplina. Requiere que la
  disciplina sea **verificable automáticamente**, no confiada.

---

## Decisión tomada

**Alternativa C**, con estas reglas como condición inseparable de la decisión —sin
ellas, C es inaceptable:

- **Regla A:** `tenant_id` NOT NULL en **toda** tabla de dominio, aun cuando el
  tenant sea alcanzable siguiendo llaves foráneas. Es redundancia deliberada y
  excepción documentada a la normalización estricta: permite acotar cualquier
  tabla sin `JOIN` y permite que el índice compuesto empiece por `tenant_id`.
- **Regla B:** prohibido cualquier query cross-tenant en código de dominio. Sólo
  el módulo de super admin —que vive fuera del dominio— agrega entre tenants.
- **Resolución de contexto:** el tenant se resuelve **una vez por request** desde
  el token o la sesión y se inyecta como contexto inmutable. `tenant_id` **jamás**
  viaja como parámetro manipulable del cliente.
- **Global scope** de tenant en todo modelo de dominio, más un **test estructural**
  que recorre los modelos y falla si a alguno le falta.
- **Índices compuestos** de tablas transaccionales que empiezan por `tenant_id`:
  `(tenant_id, branch_id, created_at)` y equivalentes.

Contexto completo del request:
`{tenant, usuario, membresía, rol activo, sucursal activa, terminal (si aplica)}`.

---

## Justificación

Las alternativas A y B compran aislamiento con costo operativo, y ese costo lo
paga un equipo pequeño en cada despliegue, durante años, para protegerse de un
riesgo que C puede neutralizar con dos mecanismos automáticos: el global scope y
el test estructural.

La diferencia decisiva es que **el riesgo de C es detectable por una máquina**. Un
modelo sin scope es una condición estructural que un test puede encontrar antes
del despliegue. Por eso el test estructural no es un extra de la decisión: es la
razón por la que la decisión es aceptable.

---

## Consecuencias

**Se gana**
- Una migración, un despliegue, una base que respaldar.
- Alta de tenant instantánea (requisito del autoservicio, D6).
- Métricas agregadas del super admin con una sola consulta.

**Se paga**
- Redundancia de `tenant_id` en toda tabla de dominio.
- Un error de scoping tiene consecuencia máxima. El proyecto asume el costo
  permanente de vigilarlo.
- "Ruidoso vecino": un tenant con carga anómala afecta a los demás hasta que se
  extraiga.

**Reglas que quedan vigentes** (todas verificables)
1. `tenant_id` NOT NULL en toda tabla de dominio. *(Revisión de migración.)*
2. Global scope de tenant en todo modelo de dominio.
   *(`tests/Architecture/TenantScopeTest.php` — debe permanecer verde siempre.)*
3. `tenant_id` nunca llega del cliente; lo resuelve el middleware.
   *(Revisión de código: ningún Form Request acepta `tenant_id`.)*
4. Cero queries cross-tenant fuera del módulo de super admin.
5. Índices compuestos de tablas transaccionales inician por `tenant_id`.
6. **Test de aislamiento de tenant por módulo**, obligatorio en la definition of
   done: crear datos en el tenant A, autenticarse en el tenant B, verificar
   invisibilidad total.

**Puerta de salida**
Extraer un tenant enterprise a base dedicada = ETL mecánico filtrando por
`tenant_id` más una conexión dedicada resuelta por el mismo middleware de
contexto. Está **previsto, no construido**. La señal que lo justificaría: un
tenant cuyo volumen degrade la latencia de los demás, o un requisito contractual
de aislamiento físico.
