# Iteración 8 — Diseño: Menús digitales + E-commerce + pasarelas

Rige **ADR-007** (Frontera E-commerce/Core: la publicación es una capa, no una copia). Este documento aterriza esa ADR en
entidades, tablas, estados y permisos. **Nada se implementa hasta que apruebes este diseño** (CLAUDE.md).

> Estado: **APROBADO — en implementación.** Tanda A (menús) y Tanda B (tienda + carrito) **completas**. Decisiones D331
> (precio por canal) y D332 (sucursales configurables). Sigue la Tanda C (checkout + pasarelas Mercado Pago y Stripe).

---

## 1. Decisiones de arranque (aprobadas)

| # | Decisión | Consecuencia |
|---|---|---|
| 1 | **Superficie pública = SPA de Vue aparte** (no Blade SSR) | Un segundo *entry* de Vite (`resources/js/public/`) montado por un shell Blade mínimo en `/m/{slug}` y `/t/{slug}`. Los datos vienen de una **API pública** (sin auth, tenant resuelto por slug). El SEO se cuida server-side sólo en el shell: `<title>`/`<meta>`/OpenGraph render en Blade desde el tenant/sucursal; el resto hidrata en el cliente. |
| 2 | **Menús digitales primero**, luego tienda, luego checkout+pasarelas, luego bandeja+entrega+cupones | Tanda A estrena la maquinaria compartida (activación de módulo, ruteo público, capa de publicación, PDF) **sin transacciones**. |
| 3 | **Ambas pasarelas** (Mercado Pago + Stripe) bajo el contrato único, en la tanda de checkout | Más superficie de una vez; el contrato se prueba con dos implementaciones desde el día uno. Una activa a la vez por tenant (D49). |
| 4 | Colisión ADR-007 corregida (mi ADR de datasets → **ADR-009**) | Hecho antes de este diseño. |

Registradas como **D326** (cuatro tandas), **D327** (SPA pública + API por slug global), **D328** (capa de publicación en
un módulo `Publishing` **no activable** — decisión del dueño del producto sobre las tres alternativas), **D329** (activación
de módulos usable + `tenancy.modules.manage`), **D330** (contrato de pasarela con Mercado Pago y Stripe a la vez).

---

## 2. La frontera, hecha tablas (ADR-007)

**Compartido con el Core — NO se duplica:**
- Artículos, precios (con override por canal), clientes, inventario, ciclo financiero.
- La capa de publicación **agrega** atributos (descripción larga, galería, orden, SEO) en tablas propias que **apuntan** al
  artículo del Core. Jamás una tabla `store_products`.

**Exclusivo de los módulos activables** (todo con `tenant_id` NOT NULL + global scope):
- `DigitalMenus`: menús por sucursal, plantillas de PDF.
- `Ecommerce`: carrito, pedidos, pagos, entregas, cupones, configuración de tienda.

**Reglas vigentes que el diseño respeta:** la tienda respeta stock (política por artículo) y el POS no; pedido pagado →
venta + diario **por eventos de dominio** (ADR-004: e-commerce nunca escribe en finanzas); el Core no referencia a estos
módulos.

---

## 3. Activación de módulo (maquinaria que ya existe, primer uso real)

Ya están: `ModuleGate` (deduce el módulo del prefijo del permiso y consulta `tenant_modules`), el middleware
`EnsureModuleActive`, y `ModuleServiceProvider` que auto-registra `Http/Routes/{api,web,public}.php` de cada módulo. Lo que
la Tanda A **estrena**:

- Poner `EnsureModuleActive:DigitalMenus` (y luego `:Ecommerce`) en las rutas de esos módulos: un tenant sin el módulo
  recibe 403 en su API y en su superficie pública. Tercer candado además de `ModuleGate` (autorización) y el guard de
  navegación del frontend.
- **Cómo se enciende un módulo:** hoy `tenancy.modules.view` es un permiso **sin ruta**. Tanda A agrega un endpoint y una
  pantalla mínima (Negocio → Módulos) para que el Propietario active/desactive Menús y Tienda. Esto le da ruta por fin a
  `tenancy.modules.*` y es lo que permite demostrar la regla «sin módulo no se ejecuta su código».

---

## 4. Tanda A — Menús digitales (diseño detallado)

### 4.1 Capa de publicación (módulo `Publishing`, compartida menús + tienda — D328)

Vive en el módulo **`Publishing`** (capa dominio, **no activable**, siempre disponible), no en Menús ni en Tienda: así los
dos módulos activables la comparten sin depender uno del otro. Permiso único `publishing.articles.manage`.

**`article_publications`** — 1 fila por artículo publicable (la capa que ADR-007 «agrega»):

| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT PK | interno |
| ulid | char(26) | público |
| tenant_id | BIGINT FK→tenants, NOT NULL | global scope |
| article_id | BIGINT FK→articles, NOT NULL | **unique(tenant_id, article_id)**: una publicación por artículo |
| long_description | text nullable | prosa (no JSON): descripción larga del menú/tienda |
| sort_order | int, default 0 | orden de aparición dentro de su categoría |
| is_visible | bool, default true | ocultar de la publicación sin tocar el catálogo |
| created_at/updated_at | | |

Índices: `unique(tenant_id, article_id)`; `index(tenant_id, is_visible)`. SEO y política de stock **no** entran aquí en la
Tanda A: el SEO es de la tienda (Tanda B) y la política de stock también (Tanda B). Aquí sólo lo que el **menú** usa.

**`article_images`** — galería, 1:N:

| Columna | Tipo | Notas |
|---|---|---|
| id / ulid | | |
| tenant_id | FK, NOT NULL | |
| article_id | FK→articles, NOT NULL | |
| path | varchar(255) | archivo en disco privado servido por ruta firmada (D55), no URL externa |
| alt_text | varchar(160) nullable | accesibilidad + SEO |
| sort_order | int | la primera (menor orden) es la portada |

Índice: `index(tenant_id, article_id, sort_order)`. **Simplificación v1 declarada:** subida de imágenes con validación de
tipo/tamaño y almacenamiento en disco local privado; sin recorte ni variantes responsivas (deuda: variantes/CDN, evolución).

### 4.2 El menú por sucursal

**`digital_menus`** — un menú publicable por sucursal:

| Columna | Tipo | Notas |
|---|---|---|
| id / ulid | | |
| tenant_id | FK, NOT NULL | |
| branch_id | FK→branches, NOT NULL | el menú es **por sucursal** (§6.8) |
| slug | varchar(80) | **unique(tenant_id, slug)** + se resuelve globalmente para `/m/{slug}` (ver §4.3) |
| is_active | bool, default false | un menú inactivo no se sirve en público |
| show_prices | bool, default true | «precios visibles o no» (§6.8) |
| theme_primary | char(7) | color de marca para el PDF/portada (hex) |
| theme_logo_path | varchar(255) nullable | logo para el PDF |
| theme_font | varchar(60) nullable | tipografía de la plantilla |

Índices: `unique(tenant_id, slug)`, `unique(tenant_id, branch_id)` (un menú por sucursal en v1), y para el ruteo público un
`unique(slug)` **global** — ver la decisión de slug abajo. El **contenido** del menú NO se curan artículo por artículo en
v1: el menú muestra los artículos **vendibles y disponibles** de la sucursal, agrupados por categoría (§18, dos niveles),
ordenados por `article_publications.sort_order` y luego por nombre; el precio sale del Core (con override por sucursal ya
existente). **Simplificación v1 declarada:** sin selección/curaduría por menú ni secciones propias (deuda: `digital_menu_items`
para incluir/excluir/reordenar por menú; hoy se controla con `is_visible` de la publicación y la disponibilidad del artículo).

### 4.3 Ruteo público y la API por slug (D326)

- **Slug global.** `/m/{slug}` y `/t/{slug}` no tienen tenant en la sesión: el **slug ES el que resuelve el tenant**. Por eso
  el slug debe ser único **globalmente**, no sólo por tenant. Se añade un índice `unique(slug)` global sobre `digital_menus`
  (y sobre la tienda en Tanda B). Alternativa descartada: slug por tenant + subdominio/dominio propio — está en «fuera de v1».
- **Shell Blade mínimo** en `DigitalMenus/Http/Routes/public.php` (grupo `public`, con `EnsureModuleActive:DigitalMenus`):
  resuelve el slug → tenant+sucursal, fija el contexto de tenant para esa petición, y devuelve el HTML con `<title>`/`<meta>`
  server-side + el punto de montaje del SPA público.
- **API pública** `GET /api/public/menus/{slug}`: sin auth, sin sesión, tenant resuelto por el slug; devuelve el menú y sus
  artículos (Resource). Es la primera API pública del sistema: va en su propio grupo, con rate-limit `public` (D55) y sin
  jamás exponer ids internos ni datos que no sean de publicación.

### 4.4 PDF del menú

`digital_menus.pdf.generate` (permiso existente). Endpoint **autenticado** (lado admin) que genera el PDF con dompdf desde
una plantilla Blade tematizada por los campos `theme_*`. Corre en la cola `exports` si resulta pesado; v1 puede ser síncrono
para un menú (pocas páginas). El editor libre de plantillas es evolución (§6.8).

### 4.5 Permisos y pantallas de la Tanda A

- Admin (Inertia+Vue, permiso `digital_menus.menus.manage`): pantalla «Menús» — editar el menú de cada sucursal (slug,
  activo, precios visibles, tema), la capa de publicación de cada artículo (descripción larga, galería, orden, visible), y
  botón «Generar PDF» (`digital_menus.pdf.generate`).
- Negocio → **Módulos** (permiso `tenancy.modules.*`): activar/desactivar Menús y Tienda.
- Público (SPA): `/m/{slug}` muestra el menú de la sucursal.

### 4.6 Plan de implementación de la Tanda A (pasos, cada uno con su prueba antes del commit)

1. **Activación de módulo usable:** endpoint + pantalla de Módulos (encender/apagar por tenant); `EnsureModuleActive` en un
   módulo de prueba. Prueba: un tenant sin el módulo recibe 403 en su ruta; con él, 200.
2. **Capa de publicación:** migraciones `article_publications` + `article_images`; modelos; subida de imágenes a disco
   privado + servido por ruta firmada. Prueba: aislamiento de tenant del módulo, y la galería/descuento por artículo.
3. **`digital_menus`:** migración + modelo + CRUD admin (por sucursal). Prueba: unicidad de slug (global y por tenant),
   aislamiento.
4. **Shell público + API pública por slug:** `public.php`, resolución de tenant por slug, `EnsureModuleActive`, Resource de
   menú. Prueba: `/api/public/menus/{slug}` devuelve el menú de OTRO tenant por su slug sin filtrar nada ajeno; un slug de un
   módulo apagado da 403.
5. **SPA pública (entry de Vite):** montaje en el shell; lista el menú por categorías con fotos y precios (si `show_prices`).
   Verificación en navegador (teléfono simulado).
6. **PDF:** plantilla Blade tematizada + dompdf + endpoint autenticado. Prueba: genera el PDF con las secciones y respeta
   `show_prices`.

---

## 5. Tanda B — Tienda: catálogo publicado + carrito (boceto)

- **Publicación de tienda:** extiende la capa con SEO (`seo_title`, `seo_description`, `store_slug`) y **política de stock por
  artículo** (`vender_siempre` / `ocultar` / `agotado`) — el enum que hace cumplir «la tienda SÍ respeta stock» (ADR-007).
  Override de precio **por canal** (POS/e-commerce) si se decide exponerlo (hoy existe override por sucursal; el de canal es
  nuevo — a decidir en el diseño de B).
- **`stores`** (configuración de tienda por tenant: slug global, nombre, tema, sucursales que atiende, pickup/envío).
- **Tienda pública** `/t/{slug}` (SPA): navegar catálogo por categorías, ficha de artículo, **carrito en sesión** (el grupo
  `public` ya trae sesión). El carrito valida stock según la política al agregar y al ir a checkout.
- Sin pago todavía: la Tanda B termina en «carrito listo para checkout».

## 6. Tanda C — Checkout + pasarelas (Mercado Pago **y** Stripe) (boceto)

- **Contrato de pasarela (D329):** interfaz del kernel `PaymentGateway { createPayment, handleWebhook, refund }` con dos
  implementaciones (`MercadoPagoGateway`, `StripeGateway`). Una activa a la vez por tenant (`ecommerce.gateways.configure`);
  credenciales **cifradas** en reposo (cast `encrypted`, como el SMTP de la It.7); cada pago registra su pasarela. Webhooks
  con **firma verificada** (D55) en ruta pública dedicada por pasarela.
- **`orders`** (pedido: cliente, sucursal, entrega, totales congelados, estado) + **`order_items`** (línea con precio/IVA
  congelados como en el POS) + **`payments`** (pasarela, referencia, estado, monto). Estados de pedido: máquina explícita
  (ver §7).
- **Ciclo financiero por eventos:** al confirmarse el pago (webhook), se emite un evento de dominio que Finanzas ya sabe
  escuchar → venta + diario con **canal `e-commerce`**. E-commerce no toca finanzas (ADR-004). Reusa la maquinaria del POS.
- Idempotencia de webhooks (llave por pasarela+referencia): un webhook reintentado no duplica el pago ni la venta.

## 7. Tanda D — Bandeja de aceptación + entrega + cupones (boceto)

- **Bandeja de pedidos** (`ecommerce.orders.view/accept/reject`): un pedido pagado entra a la bandeja; aceptarlo genera las
  comandas (reusa el ruteo por área del POS) y descuenta inventario por evento; la **aceptación automática es configurable**
  (D51). Máquina de estados: `pending_payment → paid → accepted → preparing → ready → completed`, con `rejected`/`cancelled`
  y su reverso financiero (reembolso por el contrato de pasarela).
- **Entrega:** pickup o **envío por zona** (`shipping_zones`: nombre, área/código postal, costo). El costo de envío entra al
  total del pedido.
- **Cupones de tienda** (`ecommerce.coupons.manage`): tipo acotado (%/monto/envío gratis), vigencia, tope de uso. Es la
  promoción del canal e-commerce; reusa el patrón de la It.6 donde aplique. Le da ruta por fin a `ecommerce.coupons.manage`
  y a `promotions.coupons.manage` (a decidir cuál cubre cupones de tienda).

---

## 8. Decisiones que necesito de ti (antes o durante el diseño detallado de cada tanda)

1. **Override de precio por canal**: ¿la Tanda B agrega override de precio **por canal** (POS≠tienda), o la tienda usa el
   precio del Core (con el override por sucursal que ya existe) en v1? El spec lo permite como «opcional».
2. **Cupones**: ¿`ecommerce.coupons.manage` (nuevo, del módulo) o `promotions.coupons.manage` (ya en catálogo, diferido en
   la It.6)? Recomiendo `ecommerce.coupons.manage` porque el cupón vive en el módulo activable.
3. **PDF del menú**: ¿público (cualquiera con el link lo baja) o sólo admin? Recomiendo sólo admin en v1.
4. La decisión abierta de la It.7 sobre **`reporting.exports.create`** sigue pendiente.

---

## 9. Definition of Done (por tanda, además de la de CLAUDE.md)

- Prueba de **aislamiento de tenant** de cada módulo nuevo (crítico: la API pública resuelve tenant por slug — hay que probar
  que un slug no filtra datos de otro negocio).
- Prueba de **módulo activable**: un tenant sin el módulo no ejecuta su código (403), en API y en superficie pública.
- Form Requests para toda entrada; Resources para toda salida; whitelist de filtros; sin ids internos en nada público.
- Idempotencia de webhooks y de la generación de comandas desde un pedido aceptado.
- Eventos de dominio documentados (pago confirmado → venta/diario).
- Verificación en navegador de la superficie pública (teléfono) y del admin.
- Suite **completa** (paralelo + serial) en verde antes de cada commit de tanda.
