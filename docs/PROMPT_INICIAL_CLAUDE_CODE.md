# PROMPT INICIAL PARA CLAUDE CODE

Copia y pega lo siguiente como primer mensaje en Claude Code, ya con los archivos colocados en el proyecto (ver instrucciones al final).

---

Lee completamente, en este orden: `CLAUDE.md`, `docs/ESPECIFICACION_MAESTRA.md` y `docs/ARQUITECTURA_MAESTRA.md`. Son la fuente de verdad del proyecto y CLAUDE.md define tus reglas de operación. Confírmame que los leíste resumiéndome en pocas líneas: (a) las 3 reglas arquitectónicas que consideras más críticas, (b) el orden de iteraciones, y (c) qué tienes prohibido hacer sin mi aprobación.

Después ejecuta la FASE 0 — Fundación del proyecto (esta fase sí está pre-aprobada):

El producto se llama **Comandia**. Úsalo como nombre del proyecto Laravel, base de datos (`comandia`) y APP_NAME.

1. Crea el proyecto Laravel más reciente estable con la estructura modular definida en ARQUITECTURA_MAESTRA §2 (`app/Modules/`), configurado para: MySQL 8 forzando InnoDB, Redis (cache y colas con las 4 colas definidas), Sanctum, Spatie Laravel Permission (modo teams), Vue 3 + Inertia con Vite, y Pest para testing. Entorno: Windows + WampServer, Node 20+.
2. Deja lista la carpeta `docs/` con los documentos maestros y crea `docs/adr/` copiando las ADR-001 a ADR-007 desde la Arquitectura Maestra como archivos individuales, más una plantilla `docs/adr/PLANTILLA.md` (Decisión, Contexto, Problema, Alternativas, Decisión tomada, Justificación, Consecuencias).
3. Crea el esqueleto del test estructural de scopes de tenant (aunque aún no haya modelos) y la configuración base de Pest.
4. Verifica que todo levanta: migraciones base de Laravel, servidor, Vite, un test de humo verde.

NO crees todavía ninguna tabla de dominio, ningún modelo de negocio, ningún módulo funcional.

Cuando la Fase 0 esté verde, inicia la ITERACIÓN 1 — Shared Kernel, SOLO en fase de diseño:

Preséntame el diseño detallado del modelo de datos del kernel: tenants (con estados, slug, suscripción/límites, módulos activables), users globales, tenant_memberships (PIN, estado, alcances de sucursal), employee_profiles, roles/permisos Spatie por tenant con el catálogo inicial de permisos agrupado por módulo, sucursales, almacenes (incluido central sin sucursal), áreas de preparación, terminales, configuración jerárquica (sistema→tenant→sucursal) y bitácora de auditoría.

Para cada tabla: columnas con tipos exactos, FKs, índices CON justificación, unique constraints, y qué es inmutable. Además: el diseño del servicio de contexto de autorización {tenant, rol activo, sucursal activa} y del middleware de resolución de tenant. Señala explícitamente cualquier decisión que los documentos maestros no cubran, con alternativas y tu recomendación.

**No escribas ninguna migration de la Iteración 1 hasta que yo apruebe ese diseño.**

---

## Instrucciones de colocación (antes de pegar el prompt)

```
C:\Dev\comandia\
├── CLAUDE.md                        ← en la raíz del repo
└── docs/
    ├── ESPECIFICACION_MAESTRA.md
    └── ARQUITECTURA_MAESTRA.md
```

1. Crea la carpeta `C:\Dev\comandia` y coloca los 3 archivos como se indica.
2. Abre Claude Code en esa carpeta.
3. Pega el prompt de arriba.
