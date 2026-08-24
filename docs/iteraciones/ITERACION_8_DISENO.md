# Iteración 8 — Diseño: Menús digitales + E-commerce + pasarelas

Rige **ADR-007** (Frontera E-commerce/Core: la publicación es una capa, no una copia). Este documento aterriza esa ADR en
entidades, tablas, estados y permisos. **Nada se implementa hasta que apruebes este diseño** (CLAUDE.md).

> Estado: **APROBADO — en implementación.** Tandas A (menús) y B (tienda + carrito) **completas**. Tanda C (checkout +
> pasarelas) **completa**: partes 1 (cuentas de cliente, D333), 2 (pedido + checkout foliado + entrega pickup/envío por
> zona), **3a** (contrato de pasarela + pasarela de prueba + webhook idempotente + ciclo financiero por eventos:
> `OnlineSale` sin sesión ni actor, ADR-010, D334–D336) y **3b** (Mercado Pago y Stripe reales sobre el contrato, con
> verificación de firma; doblados con `Http::fake` en pruebas) entregadas. **Tanda D en curso:** D1 completa —parte 1
> (máquina de estados + bandeja + `AreaRouter` + inventario al aceptar + auto-aceptación, D337–D339) y parte 2 (comandas:
> impresión + pantalla de cocina reusando Printing/Pos, D340)—; **D2 parte 1** (rechazo + reembolso + reversa de la venta +
> estados de entrega, D341) entregada; **D3** (cupones: administración + canje, D342–D343) entregada; **D2 parte 2**
> (reembolsos reales de Stripe/Mercado Pago, D344) entregada. **Tanda D completa → implementación de la Iteración 8
> completa;** queda el repaso de cierre (Definition of Done, consistencia ADR/D, deudas declaradas).

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

## 6. Tanda C — Checkout + pasarelas (Mercado Pago **y** Stripe)

### 6.1 Parte 3a — Contrato + pasarela de prueba + ciclo financiero (ENTREGADA)

- **Contrato de pasarela (D329):** interfaz del kernel `PaymentGateway { createCheckout, parseWebhook }` (el reembolso llega
  con la Tanda D). Implementación `FakeGateway` para pruebas y demo; `PaymentGatewayFactory` mapea el nombre a la clase —
  agregar una pasarela es añadirla al mapa, sin tocar el checkout ni el webhook—. Una activa a la vez por tenant
  (`ecommerce.gateways.configure`, el permiso más restringido); credenciales en **columnas cifradas discretas**
  (`public_key`/`secret_key`/`webhook_secret`, cast `encrypted`, sin JSON en dominio), que **nunca** vuelven por la API.
- **`payments`** (inmutable: pasarela, referencia, estado, monto; **único (tenant, pasarela, referencia)** para la
  idempotencia del webhook). `orders`/`order_items` ya venían de la parte 2. La ruta del webhook queda exenta de CSRF
  (`t/*/webhook/*`), no de firma —la firma la verifica la pasarela (D55)—.
- **Ciclo financiero por eventos (ADR-010, D334):** al confirmarse el pago (webhook), tras el commit se emite
  `EcommerceOrderPaid` → Finanzas asienta la venta e Inventory descuenta. E-commerce no toca finanzas ni el kardex (ADR-004).
  La venta se asienta con un **tipo propio `OnlineSale`**, que **no exige sesión de caja** (§6.3 queda intacto para el
  mostrador) y va **sin actor de personal** (asiento automático). El actor pasó a nullable en la base y su candado se movió
  al servicio, salvo para `OnlineSale` (D335). El corte de caja ignora la venta en línea por construcción (no tiene sesión).
- **Idempotencia de webhooks (D336):** un aviso reintentado choca con el único (pasarela, referencia) y no re-procesa ni
  re-emite el evento; la venta, además, es idempotente por (documento, tipo) en el diario.
- **Frontend:** el checkout redirige a `payment_url` (checkout alojado); pantalla de admin de la pasarela
  (`/admin/pasarela`). El demo siembra una tienda con la **pasarela de prueba** activa, para verse de extremo a extremo.
- **Simplificación v1 declarada:** se asienta la venta de productos; el pago por pasarela no se journaliza como caja y el
  envío no se separa como ingreso (evolución, ADR-010).

### 6.2 Parte 3b — Pasarelas reales (ENTREGADA)

- `MercadoPagoGateway` y `StripeGateway` sobre el mismo contrato, **sin SDK** (cliente HTTP de Laravel, para no sumar
  dependencias y dejar la firma explícita):
  - **Stripe:** `createCheckout` crea una Checkout Session (monto en centavos, `client_reference_id` = ULID del pedido) y
    manda al cliente a la URL que devuelve. `parseWebhook` verifica el HMAC de `Stripe-Signature` sobre `{t}.{cuerpo}` con
    tolerancia de 5 min y traduce `checkout.session.completed`.
  - **Mercado Pago:** `createCheckout` crea una preferencia (`external_reference` = ULID) y manda al `init_point`.
    `parseWebhook` verifica el HMAC del manifiesto `id:…;request-id:…;ts:…;` de `x-signature` y **consulta el pago**
    (`/v1/payments/{id}`) para leer estado, monto y referencia.
- Se registran en el `PaymentGatewayFactory`; el checkout y el webhook no cambian (ADR-007). En pruebas se doblan con
  `Http::fake` (sin red); las llaves reales las pone el negocio en `/admin/pasarela`. La verificación de firma se prueba
  con firma válida y con firma inválida (rechazada antes de consultar nada).
- **Simplificación v1 declarada:** un solo renglón por el total del pedido (no se replican items ni impuestos en la
  pasarela), moneda MXN. El reembolso llega con la Tanda D.

## 7. Tanda D — Bandeja de aceptación + entrega + cupones

Aprobada y dividida en tres partes (decisiones 1–6 confirmadas contigo). D1 en curso.

### 7.1 D1 — Máquina de estados + bandeja + comandas (ENTREGADA)

**Parte 1 (entregada):**
- **Máquina de estados** (D339): enum `OnlineOrderStatus` (`pending_payment → paid → accepted → ready → completed`, +
  `failed`/`rejected`/`cancelled`), transiciones validadas por `Order::transitionTo()`. `preparing` plegado en `accepted`.
- **`AreaRouter`** (D337): sonda del kernel que expone el ruteo por área del POS sin que Ecommerce dependa de Pos. Cada
  línea congela `preparation_area_id` al hacer el pedido.
- **Aceptar** (`ecommerce.orders.accept`) → `paid → accepted`, sella actor y fecha, emite `EcommerceOrderAccepted`. El
  **inventario se descuenta al aceptar** (D338), del almacén del área, reusando `DeductSoldItems`. **Aceptación automática**
  configurable por tienda (`stores.auto_accept_orders`, D51).
- **Bandeja** (`ecommerce.orders.view`): `GET /api/v1/orders` filtrable por estado + pantalla admin `/admin/pedidos`.

**Parte 2 (entregada):** las **comandas** (D340): al aceptar, `Printing` imprime la comanda por la impresora del área y
`Pos` la manda a la pantalla de cocina, ambos reaccionando a `EcommerceOrderAccepted` (decisión 6: reuso pleno). Mismo
contrato de payload (`RenderTicketPayload::forEcommerceComanda`, `kind='command'`) y mismo canal/evento de difusión
(`AreaOrderCommanded`) que el mostrador — la pantalla de cocina no cambió. Sin `PosTicket`: se encola un `print_job` de tipo
`ticket` con `pos_ticket_id` nulo, lo que relajó la mitad de `ticket` del `print_jobs_kind_shape_chk` (el contenido vive en
el payload). Se descartó generalizar `pos_tickets` a una comanda del kernel (refactor mayor del POS para v1).

### 7.2 D2 — Rechazo + reembolso + estados de entrega

**Parte 1 (entregada, D341):** el contrato de pasarela gana `refund(...)`. Rechazar un pedido **pagado y sin aceptar** →
`rejected` + reembolso (pasarela de prueba) + `payment` con `status='refunded'` + **reversa de la `OnlineSale`** en el diario
(decisión 5, ADR-010 regla 4), anclada en el pago de reembolso (por la idempotencia del diario) y enlazada a la venta por
`reverses_movement_id`. Sin reversa de kardex: descontar-al-aceptar (D338) hace que un pedido sin aceptar nunca movió stock.
Avance de entrega `accepted → ready → completed` por endpoints del personal + botones en la bandeja.

**Parte 2 (entregada, D344):** reembolsos reales de Stripe y Mercado Pago. El id del cargo de la pasarela
(`payment_intent` / id del pago) se captura al confirmar el webhook (`WebhookResult.gatewayPaymentId` →
`payments.gateway_payment_id`) y `refund()` llama a `POST /v1/refunds` (Stripe) / `POST /v1/payments/{id}/refunds` (Mercado
Pago). Dobladas con `Http::fake`, incluido el flujo completo de rechazo con Stripe.

### 7.3 D3 — Cupones de tienda (D342)

Tabla **`coupons`** propia en Ecommerce (decisión 2; las promociones de la It.6 están atadas a la sesión de caja y no tienen
código ni envío gratis): código único por negocio, tipo (%/monto/envío gratis, con `CHECK` de valor por tipo), vigencia,
tope de uso global, límite por cliente. `ecommerce.coupons.manage` (descartado `promotions.coupons.manage`, colgado desde la
It.1, D72).

**Parte 1 (entregada):** administración de cupones (crear/listar/editar/quitar) + `CouponType` + validación por tipo +
pantalla admin `/admin/cupones`.

**Parte 2 (entregada, D343):** canje en el checkout — `ResolveCoupon` valida (activo, vigente, bajo tope global y límite por
cliente) y calcula el descuento; `PlaceOrder` guarda `coupon_id` + `discount_total`, registra un `coupon_redemption`
inmutable (uno por pedido) y sube `uses_count` en la transacción del folio. La venta se asienta **neta** vía
`Order::saleAmount()` (= `subtotal − discount_total`); `free_shipping` pone el envío en cero. Input de cupón en la tienda +
cupón de bienvenida en el demo.

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
