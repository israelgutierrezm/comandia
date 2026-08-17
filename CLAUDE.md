# CLAUDE.md — Reglas del proyecto Comandia

## Qué es este proyecto

**Comandia** — SaaS multi-tenant de administración y punto de venta para negocios de alimentos y bebidas (restaurantes, cafeterías, fondas, bares). Monolito modular en Laravel + Vue 3 + MySQL 8, con app Flutter futura y REST API como ciudadano de primera clase.

## Documentos fuente de verdad (LEER ANTES DE CUALQUIER TAREA)

1. `docs/ESPECIFICACION_MAESTRA.md` — visión, módulos, reglas de negocio (sección 6 es LEY), glosario normativo, decisiones D1–D57.
2. `docs/ARQUITECTURA_MAESTRA.md` — estilo arquitectónico, ADR-001 a ADR-007, convenciones de datos, hoja de ruta de iteraciones (sección 14).

Si una instrucción mía contradice estos documentos, DETENTE y señálalo antes de implementar. Toda decisión que contradiga una ADR vigente exige redactar una ADR nueva que la reemplace, no un cambio silencioso.

## Rol esperado

Actúa como Senior Architect + Tech Lead, no solo como generador de código. Si mi petición tiene problemas: dímelo, explica por qué, propón alternativa, compara y recomienda. No seas complaciente. No inventes reglas de negocio críticas: si falta información, pregunta.

## Entorno de desarrollo

- Windows + WampServer (MySQL 8, PHP), Node 20+ para Vite.
- PowerShell como shell; cuidado con política de ejecución.
- Laravel: forzar InnoDB en config de base de datos.
- Redis para cache y colas (colas: `critical`, `default`, `exports`, `printing`).

## Reglas arquitectónicas NO NEGOCIABLES

### Multi-tenancy (ADR-002)
- `tenant_id` NOT NULL en TODA tabla de dominio, aunque sea alcanzable por FKs.
- Global scope de tenant en todo modelo de dominio. Existe un test estructural que recorre modelos y falla si falta el scope: mantenerlo verde.
- PROHIBIDO cualquier query cross-tenant en código de dominio. Solo el módulo de super admin agrega entre tenants.
- El `tenant_id` se resuelve del token/sesión por middleware; JAMÁS llega como parámetro del cliente.
- Índices compuestos de tablas transaccionales inician por `tenant_id`.

### Datos
- SIN JSON en datos de dominio. JSON solo en: bitácora de auditoría (before/after) y payloads de trabajos de impresión.
- Tablas en plural inglés, sin prefijos (convención Laravel). Excepciones: documentarlas.
- PK autoincrement BIGINT interno + ULID público en entidades expuestas por API. Nunca exponer IDs secuenciales.
- Inmutables (sin UPDATE/DELETE, corrección por reversa o nuevo registro): diario financiero, kardex, historial de precios, historial de costos, bitácora de auditoría, pagos.
- Dinero: DECIMAL(12,2). Cantidades de inventario: DECIMAL(12,4) en unidad base.
- Timestamps en UTC; presentación con zona horaria de la sucursal.
- Foliación por (tenant, sucursal, tipo_documento, serie) con incremento bajo lock, sin huecos.
- Ningún índice sin justificación escrita en el diseño de la iteración.

### Módulos y eventos
- Estructura por módulo: `app/Modules/{Modulo}/` con Domain, Application, Infrastructure, Http, Events, Listeners, Jobs, database.
- Dependencias: todo módulo puede depender del shared kernel; el kernel de nadie. Efectos cruzados SOLO por eventos de dominio: el POS jamás escribe en finanzas o inventarios directamente.
- Jobs idempotentes obligatorios (llave de idempotencia por documento origen + tipo). Re-despachar un job NUNCA duplica un movimiento.
- Módulos activables (e-commerce, menús): verificación por middleware; un tenant sin el módulo no ejecuta su código.

### Autorización
- Spatie con teams = tenant. Verificación SIEMPRE por el servicio de contexto `{permiso, rol activo, sucursal activa}` — NUNCA `$user->can()` directo (Spatie suma roles; aquí opera el rol activo, D9).
- Acciones sensibles del POS (descuentos, cancelación post-comanda, abrir cajón, autorizaciones) exigen PIN y registran al actor real en auditoría.
- Catálogo de permisos cerrado, definido en seeder versionado. El tenant combina permisos en roles; no inventa permisos.

### Vocabulario (glosario normativo de la Especificación, sección 7)
- Markup = utilidad/costo (el % configurable). Margen = utilidad/precio (reportes). PROHIBIDO usarlos como sinónimos.
- Orden (se prepara) ≠ Cuenta (se cobra) ≠ Comanda (fragmento por área). Respetar en nombres de clases, tablas y UI.
- Artículo unificado con capacidades (vendible/inventariable/insumo/producible); no existen tablas "products" vs "supplies".

### Reglas de negocio críticas (detalle completo en Especificación §6)
- El POS NUNCA se bloquea por inventario; descuento asíncrono; existencias negativas permitidas.
- Precios IVA incluido como dato maestro; desglose interno calculado.
- El sistema sugiere precios; el humano decide. Todo cambio de precio/costo se historiza.
- Cortes calculados del diario financiero, nunca almacenados como verdad paralela.
- La tienda en línea SÍ respeta stock (configurable por artículo); el POS no.

## Definition of Done (por cada entrega)

1. Tests: unit de dominio + feature de API + **test de aislamiento de tenant del módulo** + tests de autorización de acciones sensibles + idempotencia de jobs si aplica.
2. Migration con índices justificados y constraints reales (FKs, unique, NOT NULL).
3. Form Requests para toda entrada; Resources para toda salida; whitelist de filtros.
4. Eventos emitidos documentados en el módulo.
5. Sin lógica crítica de negocio en Vue (el frontend previsualiza; el backend decide).
6. Documentación de la iteración actualizada (decisiones nuevas → registro; contradicción con ADR → nueva ADR).

## Flujo de trabajo

- Seguimos la hoja de ruta de ARQUITECTURA_MAESTRA §14 (11 iteraciones). Cada iteración: ANÁLISIS → PROPUESTA → DECISIONES → APROBACIÓN → DISEÑO → IMPLEMENTACIÓN → PRUEBAS → REVISIÓN.
- **No escribas migrations ni código de una iteración cuyo diseño no he aprobado explícitamente.** Presenta primero el diseño (entidades, relaciones, FKs, índices, constraints, estados, permisos) y espera mi aprobación.
- Si detectas que una tarea requiere una decisión arquitectónica no cubierta por los documentos: detente, plantéala con alternativas y recomendación, espera decisión.
- Simplificaciones/MVP: identifícalas explícitamente, di qué deuda generan y cómo evolucionan. Nada de soluciones temporales silenciosas.
- Sin sobre-ingeniería: prohibido introducir microservicios, event sourcing, CQRS formal, Elasticsearch, Kafka, Kubernetes sin ADR aprobada.

## Idioma

- Documentación, comentarios de negocio, mensajes de UI y validación: español mexicano con acentuación correcta y completa.
- Código (clases, tablas, variables, rutas): inglés.
