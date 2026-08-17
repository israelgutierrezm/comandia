# Comandia

SaaS multi-tenant de administración y punto de venta para negocios de alimentos y
bebidas: restaurantes, cafeterías, fondas y bares.

Monolito modular en Laravel 13 + Vue 3 + MySQL 8, con REST API como ciudadano de
primera clase y app Flutter prevista.

---

## Documentos fuente de verdad

Léelos antes de tocar código. En caso de conflicto, mandan ellos.

| Documento | Contenido |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Reglas de operación del proyecto. Las reglas arquitectónicas son **no negociables** |
| [`docs/ESPECIFICACION_MAESTRA.md`](docs/ESPECIFICACION_MAESTRA.md) | Visión, módulos, reglas de negocio (§6 es ley), glosario normativo, decisiones D1–D57 |
| [`docs/ARQUITECTURA_MAESTRA.md`](docs/ARQUITECTURA_MAESTRA.md) | Estilo arquitectónico, convenciones de datos, hoja de ruta de iteraciones (§14) |
| [`docs/adr/`](docs/adr/README.md) | ADR-001 a ADR-007 en extenso, más la plantilla |
| [`docs/REGISTRO_DECISIONES.md`](docs/REGISTRO_DECISIONES.md) | Decisiones desde D58 |
| [`docs/ENTORNO_LOCAL.md`](docs/ENTORNO_LOCAL.md) | Entorno de desarrollo Windows + WampServer |
| [`docs/FASE_0.md`](docs/FASE_0.md) | Qué se construyó en la fundación y qué se dejó fuera |
| [`docs/iteraciones/`](docs/iteraciones/ITERACION_1_DISENO.md) | Diseño detallado de la iteración en curso |

---

## Puesta en marcha

Requisitos: PHP 8.3+, Composer, Node 20+, MySQL 8, y Redis (ver
[`docs/ENTORNO_LOCAL.md`](docs/ENTORNO_LOCAL.md) §3 si aún no lo tienes).

```bash
composer install
```

```bash
cp .env.example .env
```

```bash
php artisan key:generate
```

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS comandia CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; CREATE DATABASE IF NOT EXISTS comandia_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

```bash
php artisan migrate
```

```bash
npm install
```

Servidor y assets, en dos terminales:

```bash
php artisan serve
```

```bash
npm run dev
```

Worker de colas, con las cuatro colas en orden de prioridad:

```bash
php artisan queue:work --queue=critical,default,exports,printing
```

---

## Pruebas

```bash
php artisan test
```

Sólo las reglas estructurales —scopes de tenant y fronteras de módulos—:

```bash
php artisan test --testsuite=Architecture
```

> `tests/Architecture/TenantScopeTest.php` **debe permanecer verde en todo momento**.
> Es la red de seguridad contra el único fallo verdaderamente catastrófico del
> producto: que los datos de un negocio se vean desde otro (ADR-002). Si falla, no se
> arregla el test: se le pone el scope al modelo, o se agrega a la lista de excepciones
> con justificación escrita.

---

## Estructura

```
app/
├── Modules/{Modulo}/     # monolito modular (ARQUITECTURA_MAESTRA §2)
├── Providers/            # AppServiceProvider, ModuleServiceProvider
└── Support/              # infraestructura transversal sin dominio
config/comandia.php       # registro declarativo de módulos, colas y prefijo de API
resources/js/             # Vue 3: Pages (shell), modules/, layouts/, components/
tests/
├── Unit/ Feature/        # dominio y casos de uso
├── Architecture/         # scopes de tenant, fronteras de módulos
├── Support/ Fixtures/    # utilidades y dobles de prueba
```

Cada módulo tiene un archivo `.module.md` con su alcance, capa, iteración y reglas de
frontera.

---

## Estado

**Fase 0 — Fundación:** completada.

**Iteración 1 — Shared Kernel:** implementada. 31 tablas, 44 llaves foráneas, 182 pruebas
verdes. Tenancy, identidad en tres capas, organización, configuración jerárquica, auditoría
inmutable, foliación sin huecos, autorización por rol activo y autorización por PIN.

**Lo que el kernel todavía NO tiene:** controladores CRUD. Los únicos endpoints son
`GET /api/v1/context` y `POST /api/v1/authorizations`, así que el kernel aún no se administra
por API ni por pantalla — un tenant existe si alguien lo crea con Tinker o un seeder. Fue una
acotación deliberada del diseño, no un olvido; el detalle está en
[`docs/iteraciones/ITERACION_1_DISENO.md`](docs/iteraciones/ITERACION_1_DISENO.md) §15.

Siguiente: cerrar el CRUD del kernel antes de entrar a la Iteración 2 — Catálogo y Costeo.
