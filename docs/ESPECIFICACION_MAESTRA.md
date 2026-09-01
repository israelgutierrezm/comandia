# ESPECIFICACIÓN MAESTRA DEL PRODUCTO
## SaaS de Administración y Punto de Venta para Alimentos y Bebidas

**Versión:** 1.0 — Cierre de descubrimiento
**Fecha:** Agosto 2026
**Estado:** Aprobada para diseño técnico detallado

---

## 1. Visión del producto

Plataforma SaaS multi-tenant para negocios de alimentos y bebidas (restaurantes, cafeterías, fondas, bares, antros) que integra administración, punto de venta, inventarios, producción y costeo, finanzas, clientes y reportes, con módulos opcionales activables por tenant (tienda en línea, menús digitales).

**Arquetipo primario de diseño:** restaurante/cafetería con mesas, meseros, comandas por área y cuentas abiertas. Bar/antro es segmento secundario (sin diseño específico en v1, sin nada que lo impida). Fonda queda cubierta como restaurante simplificado.

**Propuesta de valor diferencial:**
- Modularidad comercial real: el tenant activa solo lo que necesita; administración y POS siempre activos como producto núcleo.
- Costeo de recetas con historial de costos y precios sugeridos: el sistema calcula, el humano decide.
- Módulo financiero que cuadra por construcción (diario inmutable tipado).
- Todo configurable con defaults sensatos: el tenant que no configura nada obtiene un restaurante funcional.

**Prioridades del proyecto (orden estricto):**
correctitud > seguridad > mantenibilidad > escalabilidad > rendimiento > velocidad de desarrollo.

---

## 2. Modelo SaaS y comercial

| Aspecto | Decisión |
|---|---|
| Alta de tenant | Asistida (super admin) desde el día 1 + autoservicio posterior. Sin periodo gratuito: el autoservicio requiere pasarela de cobro del SaaS operativa. |
| Modelo de cobro | Suscripción con límite de usuarios + costo por sucursal. Almacén no cuenta como sucursal. La forma comercial exacta se define al final; la arquitectura soporta límites y medición configurables desde el día 1. |
| Regla arquitectónica | Ninguna lógica de negocio consulta "el plan": consulta límites y módulos activos. La suscripción es entidad propia aunque el cobro real se implemente al final. |
| Super admin | Panel propio. Ve metadatos y métricas agregadas de tenants (plan, uso, volumen). NO ve datos operativos finos salvo modo soporte explícito y auditado. |
| Identificación | URL única con selección de tenant al login (v1). Slug de tenant desde el día 1 para superficies públicas (menú QR, tienda: `/m/{slug}`, `/t/{slug}`). Subdominios como evolución. |
| Mercado | México exclusivamente en v1: MXN, es-MX, IVA mexicano, CFDI. Sin multi-moneda ni multi-país. Zona horaria por sucursal. Llaves de configuración locale/currency preparadas sin UI de cambio. |
| Escala de diseño | Decenas de tenants año 1; tenants de 1–5 sucursales; pico ~500–1,000 cuentas/día en el tenant más pesado. |
| Suspensión | Tenant que deja de pagar: definición pendiente en la fase comercial (suspensión/solo lectura/borrado diferido). El modelo de estados del tenant lo soporta. |

---

## 3. Estructura organizacional del tenant

```
Tenant (empresa)
├── Almacén central (0..N, sin sucursal, surte a todas)
└── Sucursales (1..N)
    ├── Almacenes (1..N por sucursal; ej. cocina, barra)
    ├── Áreas de preparación (cocina, barra, parrilla...)
    ├── Terminales POS
    ├── Cajas / sesiones de caja
    └── Planos / zonas de mesas (1..N)
```

- **Áreas de preparación** son entidad de primera clase: destino de comandas (impresora, KDS futuro) **y** punto de consumo de inventario (cada área descuenta del almacén que se le configure).
- Topología de almacenes configurable: desde 1 almacén default por sucursal hasta consumo fino por área. El modelo soporta el caso rico; la configuración degrada hacia lo simple.

---

## 4. Identidad, usuarios y autorización

### 4.1 Tres capas de identidad
1. **Usuario global** (`users`): correo único en todo el SaaS, contraseña, nombre por partes. Puede pertenecer a N tenants independientes.
2. **Membresía por tenant**: pertenencia, roles asignados, PIN de terminal (4–6 dígitos, bloqueo por intentos), alcance de sucursales, estado. El PIN de un tenant no es el PIN de otro.
3. **Perfil de empleado por tenant**: CURP (acepta "extranjero"), RFC, NSS, fecha de nacimiento. Datos laborales, base del futuro módulo de nómina. Puede existir empleado sin credenciales de acceso (ej. lavaloza en nómina que jamás inicia sesión).

### 4.2 Roles y permisos (sobre Spatie)
- **Catálogo de permisos fijo del sistema**; el tenant combina permisos en roles propios, no inventa permisos.
- **Rol "Propietario"** por tenant: todos los permisos, no borrable, no editable.
- **Roles plantilla** al crear tenant: Gerente, Cajero, Mesero, Mesero con cobro, Almacenista — editables/eliminables por el tenant.
- **Roles múltiples con rol activo** (modelo Acadion): el usuario opera bajo un rol a la vez; permisos efectivos = rol activo + alcance de sucursales. La verificación de permisos evalúa el contexto {tenant, rol activo, sucursal activa}, no la suma de roles (envoltorio sobre Spatie).
- **Alcances**: permisos con alcance de tenant, sucursal(es) o almacén(es). Un empleado puede operar en varias sucursales del mismo tenant.
- **PIN en terminal**: la terminal queda abierta; cada acción sensible (descuento, cancelación post-comanda, abrir cajón, autorización) pide PIN e identifica al actor real, independiente de la sesión de caja.
- Gerentes pueden crear usuarios de su alcance si su rol lo permite (permiso, no hardcode).

---

## 5. Mapa de módulos

### Núcleo (siempre activo)
| Módulo | Contenido |
|---|---|
| **Tenancy** | Tenants, suscripción/límites, módulos activables, slug, estados |
| **Identidad y Acceso** | Usuarios, membresías, perfiles de empleado, roles, permisos, PIN, 2FA |
| **Organización** | Sucursales, almacenes, áreas de preparación, terminales, planos/zonas |
| **Configuración** | Sistema jerárquico: default sistema → tenant → sucursal, resolución en cascada |
| **Catálogo** | Artículos unificados, categorías (2 niveles), etiquetas, unidades y conversiones, presentaciones de compra, precios e historial, modificadores |
| **Recetas y Costeo** | Recetas, sub-recetas en cascada, rendimiento por insumo, costeo por último costo, historial de costos, precio sugerido con markup y redondeo |
| **Inventarios** | Existencias, kardex, entradas/salidas/ajustes, lotes y caducidades (FEFO), conteos físicos, mermas tipificadas, transferencias con estados |
| **Compras** | Proveedores, catálogo de precios por proveedor, recepción directa con costos |
| **POS** | Órdenes, comandas por área, cuentas (dividir/mover/juntar), pagos multi-línea, propinas, cancelaciones, para llevar, sesiones de caja, promociones |
| **Mesas y Layout** | Editor SVG, vista de piso en vivo, unión temporal de mesas |
| **Finanzas** | Diario inmutable tipado, cortes, gastos (caja y fuera de caja), retiros/depósitos, liquidación de propinas, crédito a clientes |
| **Clientes** | Clientes por tenant, perfiles fiscales (0..N), direcciones (0..N), historial, cumpleaños, crédito |
| **Reportes** | Motor declarativo, exportación PDF/Excel por colas, vistas guardadas, reportes programados |
| **Dashboards** | Constructor de dashboards sobre el motor de reportes, metas configurables |
| **Auditoría** | Bitácora técnica inmutable (12 meses caliente + archivado) |
| **Notificaciones** | Centro interno por usuario/rol: stock bajo, caducidades, diferencias de corte, transferencias pendientes |
| **Impresión** | Trabajos de impresión con estado, ruteo por área, agentes locales |

### Módulos activables por tenant
| Módulo | Contenido |
|---|---|
| **Menús digitales** | Capa de publicación de catálogo: menú QR público por sucursal, generación de PDF con plantillas parametrizables |
| **E-commerce** | Tienda pública por slug, carrito, checkout, pasarelas (Mercado Pago / Stripe, una activa a la vez), pedidos con bandeja de aceptación, cupones, entrega pickup/envío por zona, configuración visual |

### Evoluciones previstas (fuera de v1, modelo de datos preparado)
Nómina · CFDI timbrado (PAC, CSD, complementos de pago, factura global) · Órdenes de compra formales · Caja chica · Conciliación bancaria · Reservaciones · Lealtad/puntos · Editor libre de plantillas PDF · Subdominios/dominios propios · Agregados materializados para reportes · Tipos adicionales de promoción · BD dedicada por tenant enterprise (puerta de salida ADR-002) · Autoservicio de alta (requiere pasarela SaaS).

> **Actualización (D350):** el **KDS (pantalla de cocina)** deja de estar diferido y entra a v1 como **MVP acotado** (tablero por área en vivo + avance de estado por línea; la cocina marca «preparando» y «listo»). Ver D350 en el registro de decisiones.

---

## 6. Reglas de negocio fundamentales por dominio

### 6.1 Catálogo y costeo
- **Artículo unificado** con capacidades combinables: vendible / inventariable / insumo / producible. No existen tablas separadas de "productos" e "insumos".
- **Vocabulario fijo:** el % de utilidad configurable es **markup sobre costo** (precio sugerido = costo × (1+%)). Los reportes muestran **margen** (utilidad ÷ precio). Prohibido usarlos como sinónimos en código, UI o documentación.
- **Costo vigente = último costo de adquisición.** Toda variación se historiza (costo, fecha, origen, actor). El promedio del periodo se muestra como referencia visual, no se usa para cálculo.
- **El sistema sugiere precio, el humano decide.** Redondeo configurable (ninguno/entero/múltiplo 5/múltiplo 10) sobre el sugerido. Semáforo de "precio desactualizado" cuando el final se desvía del sugerido. Historial de precios inmutable (quién, cuándo, anterior→nuevo).
- **Sub-recetas en doble modalidad:** artículo producible con receta propia (costeo en cascada) o insumo directo con costo capturado. Detección de ciclos obligatoria.
- **Rendimiento por insumo** en receta (default 100%): ajusta el costo efectivo por unidad utilizable.
- **Modificadores:** grupos con reglas (obligatorio/opcional, mín/máx selecciones, permite cantidad), modificador con precio adicional e impacto en receta por unidad. Selección múltiple con cantidades (ej. 3 shots). Se imprimen en comanda y suman al costeo.
- **Precios:** IVA incluido como dato maestro; desglose interno automático (tasa configurable 16%/8%/exento por tenant, override por sucursal). Precio base con override por sucursal y por canal (POS/e-commerce).
- **Disponibilidad por canal:** activo en POS, oculto en menú, agotado en tienda son estados independientes.
- Menú por horario/disponibilidad (desayunos hasta 13:00, menú del día entre semana).

### 6.2 Inventarios
- **El POS nunca se bloquea por inventario.** La venta siempre procede; el descuento de insumos es asíncrono (colas) y las existencias negativas están permitidas. El inventario del sistema es teórico y se reconcilia con conteos físicos formales (conteo → variance → ajuste masivo auditado).
- Kardex como fuente de verdad; existencia como acumulado. Lotes/caducidades **opcionales por artículo**; salidas con FEFO automático sin selección manual obligatoria.
- Transferencias con máquina de estados completa (solicitud→autorización→preparación→envío→recepción); pasos omitibles por configuración. Recepción con diferencias genera merma en tránsito automática.
- Mermas con catálogo de motivos por tenant, permiso específico, umbral de monto con autorización superior, evidencia fotográfica opcional.
- Compras v1: proveedor + recepción directa con costos → alimenta inventario e historial de costos. Catálogo de precios por proveedor para comparación y detección de subidas.

### 6.3 POS
- **Tres entidades:** Orden (lo que se prepara), Cuenta (lo que se cobra), Comanda (fragmento de orden ruteado a un área). Una cuenta acumula N órdenes.
- **Cuenta:** abierta → cuenta solicitada (imprime ticket de cierre / pre-cuenta, bloqueo de items configurable) → cerrada → pagada (+cancelada). Ticket final al pagar con desglose de pagos y propina.
- **Item:** capturado → comandado → preparando → servido (+cancelado). Cancelar no comandado = borrar. Cancelar comandado = motivo + permiso + comanda de cancelación al área + destino configurable (merma si ya se preparó).
- **Operaciones de cuenta historizadas:** dividir (por items o partes iguales), mover items entre cuentas, juntar cuentas. Mesa se libera cuando todas las sub-cuentas están pagadas.
- **Pagos multi-línea:** N líneas por cuenta (método, monto, referencia). Propina **por línea de pago**, asociada al mesero de la cuenta. Cambio calculado y registrado. Métodos del sistema (efectivo/tarjeta/transferencia) + métodos custom del tenant, cada uno con bandera "afecta cajón".
- **Sesión de caja:** apertura (fondo, actor, terminal) → operación → precorte ciego (configurable, recomendado) → corte (esperado del diario vs declarado, por método) → cierre. Retiros parciales contra la sesión. Sin sesión abierta no hay cobro. Toda venta/pago/retiro/cancelación pertenece a una sesión.
- **Para llevar:** orden+cuenta comprimidas, sin mesa, identificador de cliente, estados de entrega, numeración visible. Cobro al ordenar o al recoger (configurable).
- **Cuentas sin mesa** del día (barra, nombre libre o cliente). **Crédito a clientes** como módulo explícito: método "crédito cliente" (no afecta cajón), saldo por cliente, abonos (afectan cajón al ocurrir), límite configurable, permiso específico. Prohibida la "cuenta que nunca se cierra".
- **Promociones (catálogo de tipos, no motor libre):** descuento %/monto por categoría/artículos en horario (happy hour), 2x1/NxM, precio especial por ventana, cupones e-commerce. Vigencia, sucursales, permiso, no acumulables (mejor gana, excepción configurable), registro por venta de promoción aplicada y monto descontado.
- **Descuentos y cortesías manuales:** por item o cuenta, % o monto, con permiso, motivo y actor registrado. Cortesía = venta en $0 que sí descuenta inventario. Zona de máxima auditoría.

### 6.4 Mesas y layout
- Editor SVG con Vue puro (ADR-003). Coordenadas lógicas persistidas (x, y, ancho, alto, rotación, forma, zona) — nunca píxeles.
- Vista de piso = mismo SVG en modo lectura con estado vivo por mesa (libre/ocupada/cuenta solicitada/por limpiar configurable; "reservada" previsto en el enum).
- Unión de mesas operativa y temporal (enlace a cuenta común, separación automática al pagar). Múltiples planos/zonas por sucursal.

### 6.5 Finanzas
- **Diario financiero inmutable, tipado y con origen (ADR-004):** append-only, sin UPDATE/DELETE; corrección por movimiento de reversa enlazado. Todo movimiento: tipo de catálogo, documento origen, tenant, sucursal, sesión, método de pago, bandera afecta-cajón, actor. Los módulos generan movimientos vía eventos de dominio; nadie escribe en finanzas directamente.
- **Cortes calculados del diario**, nunca almacenados como verdad paralela. Diferencia = movimiento tipado.
- Gastos desde caja (afectan arqueo) y fuera de caja (transferencia/tarjeta empresa), mismo catálogo de categorías, permiso, comprobante opcional, umbral de autorización.
- Retiro→depósito con referencia bancaria simple (banco, fecha, folio). Liquidación simple de propinas (movimiento tipado, afecta cajón).

### 6.6 Clientes y facturación
- Cliente por tenant, aislamiento absoluto. 0..N perfiles fiscales, 0..N direcciones. Alta express en POS (nombre+teléfono), asociación a cuenta opcional. Historial: consumos, crédito, pedidos e-commerce, cumpleaños, notas.
- **CFDI-ready sin timbrado (ADR-005):** captura validada de RFC, razón social a la letra del SAT, CP fiscal, régimen y uso CFDI (catálogos oficiales); ticket con folio facturable. Timbrado (PAC, CSD, cancelaciones, complementos, global) como primera gran evolución.

### 6.7 Reportes, dashboards y auditoría
- **Motor declarativo (ADR-006):** reporte = definición (dataset, columnas, filtros, agrupaciones, permiso); un endpoint genérico valida contra la definición, aplica scoping y ejecuta. Frontend autoconfigurado desde la definición. Export PDF/Excel por colas con notificación. Vistas guardadas. Reportes programados desde v1 (scheduler + correo + notificación).
- **Constructor de dashboards sobre el motor:** widget = reporte + configuración de visualización (número, serie, barras, pastel, top-N, semáforo vs meta) + rango temporal (incl. comparativo). Grid drag&drop, múltiples dashboards, publicables por rol, por sucursal o consolidados. Metas configurables por sucursal/periodo. Permisos heredados del reporte.
- **Auditoría en dos capas:** históricos de dominio (precios, costos, kardex, diario, transferencias — datos de negocio, para siempre) y bitácora técnica (actor, acción, entidad, antes/después, IP, terminal; login, configuración, usuarios/roles, acciones sensibles del POS, precios). Inmutable, permiso de auditoría, retención 12 meses caliente + archivado (política de sistema).

### 6.8 E-commerce y menús digitales
- **Frontera con el Core (ADR-007).** Compartido: artículos (capa de publicación agrega descripción larga, galería, orden, SEO), precios (override por canal opcional), clientes, inventario (la tienda SÍ respeta stock: vender siempre/ocultar/agotado por artículo), ciclo financiero (pedido pagado → venta y diario con canal e-commerce). Exclusivo: carrito, checkout, pasarelas, estados de pedido, entrega (pickup/envío por zona), promociones e-commerce, configuración visual, URL pública.
- El cliente elige sucursal (default si hay una). Pedido pagado → bandeja de aceptación → comandas (automático configurable).
- **Pasarelas:** contrato único (crear pago, webhook, reembolso) con dos implementaciones: Mercado Pago y Stripe. Una activa a la vez por tenant; credenciales cifradas; cada pago registra su pasarela; agregar pasarela = implementar contrato.
- **Menús digitales = capa de publicación sin transacción:** menú QR por sucursal (secciones, orden, fotos, precios visibles o no, disponibilidad) y PDF desde plantillas parametrizables (colores, logo, tipografía). Editor libre de plantillas: evolución.

### 6.9 Transversales
- **Configuración dual (D20):** toda capacidad relevante es comportamiento activable; defaults sensatos; toggles en el sistema jerárquico, nunca columnas sueltas; todo toggle justifica su caso de uso.
- **Foliación por sucursal** con serie configurable y secuencia sin huecos por tipo de documento.
- **Notificaciones internas** por usuario/rol: stock bajo, caducidad próxima, diferencia de corte, transferencia pendiente, export listo, reporte programado.
- **Impresión:** trabajo de impresión con estado (contenido, área destino, reintento, reimpresión, auditoría de impresión). Entrega por agente local: app Flutter como puente (v1) y agente de escritorio Windows como segunda implementación.
- **Tiempo real:** Reverb (estado de mesas, bandeja de pedidos, comandas) con fallback de polling.
- **Apps:** web Vue (administración + POS) y app Flutter (supervisión: ventas, reportes, cierres, dashboard; y captura de pedidos en tableta; puente de impresión).
- **Riesgo aceptado:** sin internet en la sucursal, el POS se detiene. El tenant es responsable de su conectividad (redundancia 4G recomendada).

---

## 7. Glosario normativo

| Término | Definición |
|---|---|
| Artículo | Unidad del catálogo con capacidades: vendible, inventariable, insumo, producible |
| Orden | Lo que se pidió y debe prepararse; genera comandas |
| Comanda | Fragmento de una orden ruteado a un área de preparación |
| Cuenta | Lo que se debe cobrar; agrupa items; recibe pagos |
| Ticket de cierre | Pre-cuenta impresa al solicitar la cuenta |
| Ticket final | Comprobante emitido al pagar, con desglose de pagos y propina |
| Sesión de caja | Periodo entre apertura y cierre de una caja: amarra ventas, pagos, retiros y corte |
| Markup | Utilidad ÷ costo. El % configurable del sistema |
| Margen | Utilidad ÷ precio. Lo que muestran los reportes |
| Área de preparación | Destino de comandas y punto de consumo de inventario |
| Membresía | Relación usuario–tenant: roles, PIN, alcance, estado |
| Movimiento financiero | Registro inmutable del diario, tipado y con documento origen |
| Merma | Salida de inventario tipificada por motivo |
| Kardex | Historial de movimientos de inventario por artículo/almacén |
| FEFO | First Expired, First Out: primero lo que caduca |

---

## 8. Registro de decisiones (D1–D57)

D1 Producto desde cero sin cliente ancla; salir a operación real temprano · D2 Arquetipo restaurante/cafetería · D3 Módulos activables por tenant como concepto de primera clase · D4 Suscripción + límite usuarios + costo por sucursal; arquitectura mide/limita, lo comercial se define al final · D5 Menús digitales como módulo (QR + PDF con plantillas) · D6 Alta asistida + autoservicio sin trial · D7 Modificadores con selección múltiple y cantidades · D8 Identidad global + membresía + perfil de empleado por tenant; PIN en membresía; empleados sin cuenta · D9 Roles múltiples con rol activo · D10 Rol Propietario + roles plantilla + catálogo fijo de permisos · D11 Topología de almacenes flexible (central + N por sucursal + consumo por área configurable) · D12 Empleado multi-sucursal · D13 Markup/margen vocabulario fijo · D14 Último costo + historial de variaciones · D15 Precio sugerido, humano decide, redondeo configurable, historial inmutable · D16 Sub-recetas doble modalidad + detección de ciclos · D17 Catálogo unificado de artículos · D18 Categorías 2 niveles · D19 Etiquetas libres por tenant · D20 Principio de configuración dual · D21 Rendimiento por insumo · D22 Unidades + conversiones + presentaciones de compra · D23 Lotes opcionales + FEFO · D24 Conteos físicos formales en v1 · D25 Transferencias con estados completos y pasos omitibles · D26 Compras v1 mínimas + catálogo de precios por proveedor · D27 Mermas con motivos, umbral y evidencia · D28 Orden/Cuenta/Comanda separadas · D29 Cobro por permiso, dos plantillas de mesero · D30 Precios IVA incluido · D31 Cuentas sin mesa del día + crédito explícito · D32 Unión de mesas temporal · D33 Reservaciones fuera de v1 (enum preparado) · D34 Múltiples planos por sucursal · D35 Diario inmutable tipado (ADR-004) · D36 Gastos desde caja y fuera de caja · D37 Caja chica: evolución · D38 Depósito con referencia simple · D39 Liquidación simple de propinas · D40 Crédito a clientes en v1 · D41 CFDI-ready sin timbrado (ADR-005) · D42 Cliente por tenant, perfiles fiscales y direcciones múltiples · D43 Cliente opcional y alta express en POS; cumpleaños · D44 Lealtad fuera de v1 · D45 Motor de reportes declarativo + programados en v1 (ADR-006) · D46 Constructor de dashboards sobre el motor de reportes + metas · D47 Retención de auditoría 12 meses + archivado · D48 E-commerce por sucursal elegida por el cliente · D49 Mercado Pago y Stripe bajo contrato de pasarela; una activa; credenciales cifradas · D50 Promociones POS v1 como catálogo de tipos acotado · D51 Bandeja de pedidos con aceptación · D52 Locale/currency preparados sin UI · D53 Foliación por sucursal con serie · D54 Sanctum + 2FA opcional + PIN con bloqueo · D55 Rate limiting + webhooks firmados + archivos seguros · D56 Impresión por trabajos + agentes locales · D57 Reverb + VPS único + backups probados + mailer.

---

## 9. Riesgos principales y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Diseñar contra un restaurante imaginario (sin cliente ancla) | MVP a manos de un negocio real lo antes posible; flujos de POS validados contra operación estándar del sector |
| Fuga de datos entre tenants (aislamiento lógico) | Reglas A/B de ADR-002, global scopes, test estructural de scopes, tests de aislamiento por módulo |
| Cortes que no cuadran | Diario inmutable con cortes calculados por construcción (ADR-004) |
| Robo hormiga no detectable | Descuentos/cancelaciones/cortesías con permiso, motivo, actor y reporte dedicado; precorte ciego |
| Alcance v1 creciendo sin control | Este documento es el corte; toda adición pasa por decisión registrada |
| Impresión física no confiable | Trabajos de impresión con estado, reintentos y auditoría; dos implementaciones de agente |
| POS caído sin internet | Riesgo aceptado y comunicado; redundancia 4G recomendada al tenant |
