# ARQUITECTURA MAESTRA
## SaaS de Administración y Punto de Venta para Alimentos y Bebidas

**Versión:** 1.0 — Base para diseño técnico detallado
**Fecha:** Agosto 2026
**Documento hermano:** ESPECIFICACION_MAESTRA.md

---

## 1. Estilo arquitectónico

**Monolito modular** (ADR-001): una sola aplicación Laravel con módulos como fronteras lógicas disciplinadas (namespaces/carpetas), no paquetes Composer ni repos separados — la disciplina la imponen las convenciones, los eventos de dominio y los tests, no la infraestructura. Diseñado para que módulos con fronteras limpias (e-commerce, reportes, impresión) puedan extraerse en el futuro si el crecimiento lo exige. Prohibido introducir microservicios, event sourcing, CQRS formal, Elasticsearch, Kafka o Kubernetes sin justificación registrada como ADR.

**Stack:**
- Backend: Laravel (PHP), REST API como ciudadano de primera clase (la consumen web y app por igual).
- BD: MySQL 8 (InnoDB), una base compartida (ADR-002).
- Frontend web: Vue 3 (administración + POS).
- App móvil: Flutter (supervisión, captura en tableta, puente de impresión).
- Autorización: Spatie Laravel Permission con envoltorio de contexto.
- Infraestructura v1: VPS único + Redis (cache y colas) + workers supervisados + Laravel Reverb (WebSockets) + mailer transaccional.

---

## 2. Mapa de módulos y reglas de dependencia

```
┌─────────────────────────────────────────────────────────┐
│  SHARED KERNEL                                          │
│  Tenancy · Identidad/Acceso · Organización ·            │
│  Configuración · Auditoría · Notificaciones             │
└─────────────────────────────────────────────────────────┘
        ▲ pueden depender del kernel; el kernel no depende de nadie
┌──────────────┬──────────────┬──────────────────────────┐
│ Catálogo     │ Inventarios  │ Finanzas (diario)        │
│ Recetas/     │ Compras      │ Clientes                 │
│ Costeo       │              │                          │
├──────────────┴──────────────┴──────────────────────────┤
│ POS · Mesas/Layout · Promociones                       │
├────────────────────────────────────────────────────────┤
│ Reportes · Dashboards · Impresión                      │
├────────────────────────────────────────────────────────┤
│ ACTIVABLES: Menús digitales · E-commerce               │
└────────────────────────────────────────────────────────┘
```

**Reglas de dependencia:**
1. Todo módulo puede depender del shared kernel; el kernel no depende de ningún módulo de dominio.
2. Las dependencias fluyen hacia abajo en el diagrama; nunca hacia arriba ni laterales directas entre módulos operativos.
3. Los efectos colaterales cruzados viajan por **eventos de dominio**, nunca por escritura directa: el POS no escribe en finanzas ni en inventarios; emite `CuentaPagada`, `ItemComandado`, y los listeners de cada módulo reaccionan.
4. Los módulos activables consultan el Core mediante sus servicios públicos; el Core ignora su existencia (un tenant sin e-commerce no ejecuta una sola línea de ese módulo).
5. Cada módulo expone: servicios públicos (contrato interno), eventos que emite, listeners que registra. Todo lo demás es privado del módulo.

**Estructura de carpetas (por módulo):**
```
app/Modules/{Modulo}/
├── Domain/          # entidades, reglas, value objects, estados
├── Application/     # servicios de caso de uso, DTOs
├── Infrastructure/  # modelos Eloquent, repositorios, adaptadores
├── Http/            # controllers, requests, resources (API)
├── Events/ · Listeners/ · Jobs/
└── database/        # migrations y seeders del módulo
```
El nivel de formalidad DDD es pragmático: entidades y servicios claros, sin liturgia (no aggregates formales ni repositorios abstractos donde Eloquent basta). La frontera es sagrada; el interior es práctico.

---

## 3. Multi-tenancy (ADR-002)

**Decisión:** BD compartida con `tenant_id`, scoping global obligatorio, diseño extraíble.

- **Regla A:** `tenant_id` NOT NULL en toda tabla de dominio, aunque sea alcanzable por FKs (redundancia deliberada, excepción documentada a la normalización estricta).
- **Regla B:** prohibido query cross-tenant en código de dominio; solo el módulo de super admin (fuera del dominio) agrega entre tenants.
- **Resolución de contexto:** el tenant se resuelve una vez por request desde el token/sesión y se inyecta como contexto inmutable. `tenant_id` jamás viaja como parámetro manipulable del cliente.
- **Global scope** de tenant en todo modelo de dominio + **test estructural** que recorre los modelos y falla si alguno carece del scope.
- **Índices compuestos** inician por `tenant_id` en tablas transaccionales: `(tenant_id, branch_id, created_at)`, etc.
- **Puerta de salida:** extraer un tenant enterprise a BD dedicada = ETL mecánico por `tenant_id` + conexión dedicada. Previsto, no construido.

**Contexto de request completo:** `{tenant, usuario, membresía, rol activo, sucursal activa, terminal (si aplica)}` — resuelto por middleware, disponible por inyección, registrado en auditoría.

---

## 4. Identidad y autorización

### 4.1 Modelo de datos conceptual
```
users (global: correo único, contraseña, nombre por partes)
  └── tenant_memberships (tenant_id, estado, PIN hash, rol default)
        ├── membership_roles (roles Spatie del usuario en el tenant)
        ├── membership_branch_scopes (sucursales permitidas)
        └── employee_profiles (CURP/extranjero, RFC, NSS, nacimiento)
              [empleado puede existir sin user → membresía sin credenciales]
```

### 4.2 Autorización por contexto (envoltorio sobre Spatie)
- Spatie almacena roles y permisos **por tenant** (teams de Spatie = tenant).
- La verificación efectiva evalúa `{permiso, rol activo, sucursal activa}`: un Gate/servicio central `Authorize::can($permiso, $contexto)` — nunca `$user->can()` directo, porque Spatie suma roles y aquí opera el **rol activo** (D9).
- Cambio de rol activo y de sucursal activa: operación de sesión, auditada.
- **PIN de terminal:** endpoint de "acción autorizada por PIN" → valida PIN de la membresía, verifica el permiso del actor del PIN en el contexto, registra en auditoría al actor real (distinto del dueño de la sesión de caja si aplica). Bloqueo tras N intentos.
- **Catálogo de permisos:** definido en código (seeder versionado), agrupado por módulo; permisos de módulos inactivos no se muestran al tenant.
- **Verificación de módulo activo:** middleware por grupo de rutas + guard en navegación del frontend.

---

## 5. Configuración jerárquica

- Entidad de configuración con niveles: **default de sistema (en código) → tenant → sucursal**; resolución en cascada con cache por tenant (invalidación al escribir).
- Llaves tipadas y registradas en código (catálogo de settings con tipo, nivel máximo de override, default). Prohibido inventar llaves desde el cliente.
- Aquí viven los toggles del principio de configuración dual (D20): forma de trabajo de almacenes, precorte ciego, bloqueo de items al solicitar cuenta, cobro de para llevar, aceptación automática de pedidos, etc.

---

## 6. Eventos de dominio y procesamiento asíncrono

**Eventos síncronos vs asíncronos — la regla:** lo que afecta la respuesta al usuario es síncrono (validación, escritura del documento, foliación); lo que es consecuencia es asíncrono por colas (descuento de inventario por receta, generación de movimientos financieros derivados, notificaciones, exports, impresión).

**Eventos nucleares del sistema (catálogo inicial):**
`OrdenComandada` → genera comandas por área + trabajos de impresión + (async) descuento de inventario por receta del almacén del área · `CuentaPagada` → movimientos del diario + ticket final · `SesionCerrada` → corte calculado + movimiento de diferencia · `CompraRecibida` → entrada de inventario + actualización de último costo + historial · `TransferenciaRecibida` → salida/entrada + mermas en tránsito · `PedidoEcommercePagado` → venta + diario + bandeja · `CostoActualizado` → recálculo de precios sugeridos en cascada (async) · `StockBajoDetectado` / `CaducidadProxima` → notificaciones.

**Colas (Redis):** colas separadas por criticidad: `critical` (inventario, finanzas), `default` (notificaciones, recálculos), `exports` (reportes pesados), `printing`. Jobs idempotentes obligatorios (un evento re-entregado no duplica un movimiento del diario: llave de idempotencia por documento origen + tipo).

**Consistencia:** el documento operativo (cuenta, orden) es la verdad inmediata; el diario y el kardex son proyecciones confiables con retraso de segundos. Los cortes esperan el drenado de la cola de la sesión (verificación al cerrar).

---

## 7. Modelo de datos — convenciones globales

- Nomenclatura Laravel: tablas en plural inglés, sin prefijos, excepciones documentadas.
- **Sin JSON en datos de dominio** (filosofía del proyecto); JSON solo en auditoría (`before/after`) y payloads de trabajos de impresión.
- PKs autoincrement BIGINT + **ULID público** en entidades expuestas por API (nunca exponer IDs secuenciales al cliente).
- `tenant_id` NOT NULL universal (Regla A). FKs con integridad referencial real.
- **Inmutables por diseño** (sin UPDATE/DELETE, corrección por reversa/nuevo registro): diario financiero, kardex, historial de precios, historial de costos, bitácora de auditoría, pagos.
- **Máquinas de estado** como columnas enum + tabla de transiciones historizada donde importa la trazabilidad (transferencias, pedidos, cuentas).
- Tablas transaccionales de alto volumen identificadas desde el diseño (order_items, movimientos de diario, kardex, auditoría): índices justificados uno a uno, particionamiento lógico por fecha como evolución.
- Foliación: tabla de secuencias por `(tenant, sucursal, tipo_documento, serie)` con incremento bajo lock — sin huecos.
- Dinero: `DECIMAL(12,2)` MXN; cantidades de inventario `DECIMAL(12,4)` en unidad base del artículo.
- Zona horaria: almacenamiento UTC, presentación con TZ de la sucursal (crítico para cortes y reportes "del día").

---

## 8. API

- **Versionada:** `/api/v1/...` desde el día uno. Sanctum: tokens para app Flutter, sesión SPA para Vue.
- Convenciones uniformes: Resources para toda respuesta; errores RFC-7807-like (código, mensaje, detalles de validación); paginación cursor para listados transaccionales y page para catálogos; filtros/orden/búsqueda con whitelist por endpoint (nunca filtros libres); status codes canónicos.
- El contexto (tenant, rol activo, sucursal activa) viaja en el token/sesión + headers de contexto operativo (`X-Branch`, `X-Terminal`) validados contra el alcance del usuario — jamás confiados a ciegas.
- Rate limiting por IP y usuario en login/PIN/webhooks; firmas verificadas en webhooks de pasarela.
- Un solo endpoint genérico para el motor de reportes (ADR-006) validado contra definiciones.
- Endpoints públicos sin autenticación (menú QR, tienda) bajo namespace propio con cache agresivo y sin datos sensibles.

---

## 9. Frontend

**Vue (web):** estructura modular espejo del backend: `modules/{modulo}/` con views, components, composables, stores (Pinia), services (API tipada). Layouts por superficie: administración, POS (pantalla completa, táctil), superficies públicas (menú/tienda). Guards de ruta por permiso + módulo activo; directiva `v-can` para elementos condicionados. Regla dura: **la lógica crítica de negocio vive en el backend** — el frontend calcula solo para previsualizar (totales de cuenta se confirman contra el servidor antes de cobrar).

**POS como superficie especial:** diseñado para touch (objetivos táctiles grandes, flujo sin teclado), estado en Pinia con sincronización por Reverb (piso de mesas, comandas), y candados optimistas: dos terminales no pueden cobrar la misma cuenta (versión de cuenta verificada al pagar).

**Flutter (app):** consume la misma API v1. Tres roles: supervisión (dashboard, reportes, cierres), captura de pedidos en tableta, y puente de impresión (recibe trabajos por WebSocket/polling, entrega ESC/POS por TCP a impresoras LAN).

**Editor de layout:** SVG + Vue puro (ADR-003), coordenadas lógicas, mismo render para editor y piso de venta.

---

## 10. Seguridad (síntesis normativa)

1. Aislamiento de tenant: ADR-002 completo + tests de aislamiento por módulo (obligatorios en definition of done).
2. Autenticación: Sanctum; contraseñas con política configurable; 2FA TOTP opcional, obligable por tenant para roles administrativos; PIN con hash y bloqueo; expiración de sesiones de terminal configurable.
3. Autorización: contexto {tenant, rol activo, sucursal}; mínimo privilegio en roles plantilla; catálogo de permisos cerrado.
4. Secretos: credenciales de pasarela y futuros CSD cifrados en reposo (cast encrypted + llave de app rotable); jamás en logs.
5. Archivos: validación de tipo real, límites, almacenamiento fuera de webroot, URLs firmadas temporales; públicas solo imágenes de menú/tienda.
6. Auditoría técnica inmutable (D47) + históricos de dominio.
7. Validación en Form Requests siempre; whitelist de filtros; sin mass-assignment abierto.
8. Cabeceras de seguridad, CORS restrictivo, HTTPS obligatorio.

---

## 11. Testing (estrategia)

- **Pirámide:** unit (dominio puro: costeo, cálculo de cuenta, promociones, redondeos), feature/API (casos de uso por endpoint), integración (eventos → listeners → proyecciones: venta → diario + kardex).
- **Tests de aislamiento de tenant:** por módulo, obligatorios: crear datos en tenant A, autenticar tenant B, verificar invisibilidad total.
- **Test estructural de scopes:** recorre modelos de dominio y falla si falta el global scope de tenant.
- **Tests de autorización:** matriz permiso×contexto para acciones sensibles (cobrar, cancelar comandado, descuentos, mermas, gastos).
- **Tests de idempotencia:** re-despachar jobs de inventario/finanzas no duplica movimientos.
- **Tests de invariantes financieras:** el diario de una sesión siempre suma el corte; una reversa siempre enlaza a su original; una cuenta pagada suma pagos = total.
- Factories por módulo; seeders de demo (tenant completo de prueba) como herramienta de QA y demos comerciales.

---

## 12. Infraestructura y operación (v1)

- VPS único: Nginx + PHP-FPM, MySQL 8, Redis, workers Horizon/supervisor por cola, Reverb, scheduler.
- Backups diarios automatizados (BD + archivos) con retención configurable y **restore probado periódicamente**.
- Monitoreo: uptime, errores agregados con alertas, métricas de colas (profundidad, fallos).
- Despliegue: pipeline simple (build assets, migrate, restart workers) con ventana de mantenimiento; zero-downtime como evolución.
- Escalamiento previsto: vertical primero; luego separación de BD a servidor propio; luego workers dedicados. Nada de esto se construye hoy.

---

## 13. Registro de ADRs

| ADR | Decisión | Estado |
|---|---|---|
| ADR-001 | Monolito modular con fronteras por eventos; extraíble, no distribuido | Aprobada |
| ADR-002 | Multi-tenancy: BD compartida + tenant_id + reglas A/B + puerta de salida | Aprobada |
| ADR-003 | Editor de layout y piso de venta en SVG + Vue puro, coordenadas lógicas | Aprobada |
| ADR-004 | Finanzas: diario inmutable tipado con origen; cortes calculados | Aprobada |
| ADR-005 | CFDI-ready sin timbrado en v1; timbrado como primera gran evolución | Aprobada |
| ADR-006 | Motor de reportes declarativo: definiciones + endpoint genérico + export por colas | Aprobada |
| ADR-007 | Frontera E-commerce/Core: publicación como capa, una sola fuente de verdad | Aprobada |

Toda decisión futura que contradiga una ADR vigente exige nueva ADR que la reemplace explícitamente (detección de contradicciones: responsabilidad del arquitecto en cada iteración).

---

## 14. Hoja de ruta del diseño técnico detallado

Orden de iteraciones propuesto (cada una: ANÁLISIS → PROPUESTA → DECISIONES → APROBACIÓN → DISEÑO → IMPLEMENTACIÓN → PRUEBAS → REVISIÓN):

1. **Shared Kernel:** tenancy, identidad (3 capas), autorización por contexto, configuración jerárquica, auditoría, organización (sucursales/almacenes/áreas/terminales). *Aquí se escribe la primera migration.*
2. **Catálogo + Recetas/Costeo:** artículos, unidades, modificadores, recetas, historiales de costo/precio.
3. **Inventarios + Compras:** kardex, existencias, lotes/FEFO, transferencias, mermas, conteos, proveedores.
4. **POS núcleo:** órdenes/comandas/cuentas, sesiones de caja, pagos/propinas, impresión (trabajos + agente).
5. **Finanzas:** diario, cortes, gastos, retiros/depósitos, crédito, liquidación de propinas.
6. **Mesas/Layout + tiempo real** (Reverb).
7. **Promociones + Clientes/CFDI-ready.**
8. **Reportes + Dashboards + Notificaciones.**
9. **Menús digitales + E-commerce + pasarelas.**
10. **App Flutter** (supervisión → captura → puente de impresión).
11. **Endurecimiento:** seguridad, rendimiento, backups/restore, observabilidad, despliegue.

La iteración 1 no inicia implementación hasta aprobar su diseño detallado (modelo de datos del kernel completo: entidades, FKs, índices, constraints).
