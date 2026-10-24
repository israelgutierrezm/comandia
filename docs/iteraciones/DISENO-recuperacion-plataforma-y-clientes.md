# Diseño: recuperar la contraseña de clientes de la tienda y de administradores de plataforma

> Estado: **Aprobado (2026-10-23) e implementado** con las opciones recomendadas: D381 (clientes) y D382 (plataforma). Desviación consciente al implementar: el límite de «Mi cuenta» de plataforma responde con el mensaje bajo el campo, como el acceso de plataforma, y no con un 429 (es un formulario de Inertia).
>
> **Qué cierra:** el pendiente «Recuperar la contraseña de administradores de plataforma y de clientes de la tienda en línea» de `docs/REGISTRO_DECISIONES.md` (tabla «Pendiente de diseño abierto por la UI»). El diseño de acceso lo dejó fuera de alcance.
>
> **Qué toma del precedente** (`docs/iteraciones/DISENO-acceso-y-sesiones.md`, D372–D374):
> - Respuesta siempre igual.
> - Correo por cola.
> - Enlaces armados sobre `app.url`, nunca sobre el `Host` de la petición.
> - Token aleatorio que sólo se guarda como hash.
> - Restablecer no inicia sesión y cierra todo lo demás.
>
> **Decisiones:** D381 (clientes) y D382 (plataforma).

## Problema

### Lo que hay hoy

**El broker de contraseñas**
- Laravel tiene **un solo broker, `users`**, que guarda en `password_reset_tokens` con el `email` como llave primaria y busca y borra sólo por correo. No sirve para los otros dos guardias:
  - El correo de un cliente se repite entre negocios (es único por negocio, D42).
  - Un mismo correo puede ser a la vez de alguien del personal, de un super admin y de un cliente.

**Administradores de plataforma (guardia `platform`)**
- `PlatformAdmin` extiende `Illuminate\Foundation\Auth\User`. Por eso hereda `CanResetPassword` y la notificación por omisión de Laravel, que tiene tres problemas: está en inglés, se manda síncrona y su enlace apunta a `route('password.reset')`, que es la pantalla del **personal**.
- No hay ruta para recuperar ni para cambiar la contraseña.
- La única vía es `comandia:platform-admin`, que tiene varios problemas:
  - Usa `updateOrCreate`, así que un correo mal escrito **crea otro super admin**.
  - Si no se pasa `--name`, le pisa el nombre al admin.
  - Exige sólo 8 caracteres.
  - Acepta la contraseña en `--password`, de modo que queda en el historial de la terminal y en la lista de procesos.
  - No rota `remember_token`.
- **Cambiar la contraseña de un super admin hoy no saca a nadie:**
  - El grupo `platform` no revisa la sesión: `AuthenticateWebSession` sólo mira el guardia `web`.
  - Como `remember_token` no se rota, una cookie de «Mantener la sesión abierta» sigue entrando.
- No hay bitácora de plataforma, porque `audit_entries.tenant_id` es NOT NULL.

**Clientes de la tienda (guardia `customer`)**
- `Customer` es un `DomainModel`: su `TenantScope` lanza sin contexto. Implementa sólo `Authenticatable`.
- La tabla `customers` no tiene `remember_token` ni `email_verified_at`, y `password` es nullable porque los clientes del POS no tienen credenciales.
- **El acceso no revisa `status`:** un cliente inactivo entra a la tienda.
- **El acceso sólo tiene `throttle:public`** (300 por minuto por IP), contra ARQUITECTURA §8, que pide límites por IP y por usuario en el login.
- El registro responde «Ese correo ya está registrado», así que ya revela qué correos tienen cuenta, y no tiene límite propio.
- **Sesiones:**
  - Las del cliente viven en el grupo `public`, con la **misma cookie que `web`**. El carrito vive en esa sesión.
  - Ninguna revisión las cierra: una sesión de cliente sobrevive a cualquier cambio de contraseña.
  - `logout` hace `invalidate()` sobre esa sesión compartida.
- **El personal puede cambiar el correo de un cliente con cuenta** si tiene `customers.customers.manage`. `SaveCustomerRequest` no valida que el correo sea único: el choque llega hasta el índice de la base.
- **La tienda (`StoreApp.vue`):**
  - No tiene router.
  - Su `api()` sólo lee `payload.title`, así que todo 422 de un Form Request se ve como «Los datos enviados no son válidos.». Ya pasa hoy con el mínimo del registro.
- **Correo:**
  - No existe ningún correo a clientes.
  - `TenantMailer::send` es síncrono.
  - El patrón a seguir es `SendMembershipInvitation`: trabajo en cola que abre el contexto del negocio.
- `stores.slug` es editable, y hay una tienda por negocio.

### Lo que se resuelve
Que un cliente y un super admin recuperen el acceso sin crear un atajo hacia la cuenta de otro, de otro negocio o de otro guardia. Que cambiar la contraseña saque de verdad a quien tenga una sesión abierta.

## Alcance

**Dentro:**
- **Clientes:**
  - «¿Olvidaste tu contraseña?» y restablecer, desde la tienda.
  - Restablecer cierra las demás sesiones (huella de sesión).
  - El acceso rechaza a los inactivos.
  - Límites propios en el acceso y en el registro (decisión 8).
  - Contención cuando el personal cambia el correo de un cliente con cuenta (decisión 5).
  - La tienda muestra los mensajes de validación reales.
- **Plataforma** (decisión 1, opción B):
  - Recuperación por consola endurecida.
  - «Mi cuenta» para cambiar la contraseña con la sesión abierta.
  - Cambiar la contraseña cierra las demás sesiones.
  - Aviso por correo al admin.
  - Constancia en un canal de log (decisión 9).

**Fuera de alcance** (cada uno queda como pendiente propio):
- **2FA TOTP** (ARQUITECTURA §10.2). Cuando llegue hay que revisar la decisión 1.
- **Verificar el correo del cliente al registrarse** (deuda de D333). Es lo que cerraría la enumeración del registro.
- **«Mi cuenta» del cliente** (cambiar la contraseña o el correo con la sesión abierta).
- **Activación por teléfono** (D333): quien conoce el teléfono de un cliente del POS sin cuenta lo reclama con cualquier correo y le pisa el nombre.
- **`logout` del cliente** hace `invalidate()` sobre la sesión compartida: saca también al personal en ese navegador y vacía el carrito.
- **Pantalla para administrar super admins**, y una bitácora de plataforma completa.
- **Alta del propietario por invitación.** `StoreBusinessRequest` acepta 8 caracteres, contra los 10 de D373.
- **«¿Olvidaste tu contraseña?» en la app Flutter** del personal.
- **`auth:clear-resets` no está programado** para `password_reset_tokens`. Es inofensivo: hay a lo más una fila por correo.

## Flujos

### A. Cliente: pedir el enlace

1. En la tienda, el panel «Iniciar sesión» gana el enlace **«¿Olvidaste tu contraseña?»**. Abre el modo `authMode = 'forgot'`: un campo de correo y el botón «Enviarme el enlace».
2. La tienda llama `POST /t/{slug}/password/forgot` (`public.store.password.email`) con `{email}`. Lo atiende `CustomerPasswordController::forgot`, que hace esto:
   - `resolveStore($slug)`. Con la tienda apagada o el módulo desactivado responde 404, como todo `/t`. No se puede recuperar mientras no se puede comprar.
   - `ForgotCustomerPasswordRequest::throttle()`. Al pasarse responde **429** con el mensaje en `title`.
   - Despacha `SendCustomerPasswordResetLink(tenantId, correo en minúsculas y sin espacios)`.
   - Responde **202** con `{"data":{"message":"Si ese correo tiene cuenta en {tienda}, te enviamos un enlace para crear una contraseña nueva. Vence en 60 minutos; revisa también la carpeta de no deseado."}}`.
   - Es el mismo texto y el mismo estado exista o no la cuenta, sea del POS o esté inactiva.
   - **La petición no toca `customers`.** La búsqueda ocurre en el trabajo, así que ni el texto ni el tiempo de respuesta dicen nada.
3. El trabajo `Ecommerce/Jobs/SendCustomerPasswordResetLink` corre en la cola `default`:
   1. Abre `TenantContext::runFor($tenantId)`.
   2. Termina sin enviar si la tienda ya no está activa o si Ecommerce se apagó entre la petición y el trabajo.
   3. Busca `Customer::query()->active()->whereNotNull('password')->where('email', $email)->first()`. Si es `null`, termina en silencio.
   4. Llama `CustomerPasswordResets::issue($customer)`. Devuelve `null` si se emitió un enlace hace menos de 5 minutos (enfriamiento); entonces termina y el enlace anterior sigue sirviendo.
   5. Arma el enlace `rtrim(config('app.url'), '/').'/t/'.$store->slug.'/restablecer/'.$token`, con el slug **leído de la base al enviar**.
   6. Envía con `TenantMailer::send($customer->email, new CustomerPasswordResetMail(…))`.
   7. **Si el envío lanza:** primero `revokeFor($customer)` y luego relanza. El reintento emite otro enlace. Nunca hay dos enlaces vivos (`unique(customer_id)`) ni salen dos correos dentro del enfriamiento.
   8. **El token en claro no viaja en la carga útil del trabajo:** nace dentro del trabajo y sólo existe en el correo. La invitación de D374, en cambio, lleva el enlace en la carga útil.
4. El correo es `Ecommerce/Mail/CustomerPasswordResetMail`:
   - Recibe `storeName`, `customerName`, `link`, `minutes`, `fromAddress` y `fromName`.
   - Asunto: «Crea tu nueva contraseña en {tienda}».
   - Texto: el enlace vale 60 minutos y una sola vez; al guardar la nueva se cierran las sesiones abiertas; si no lo pediste, ignora el correo y tu contraseña no cambia.

### B. Cliente: restablecer

1. **El enlace del correo** es `GET /t/{slug}/restablecer/{token}` (`public.store.password.reset`), con `token` = `[A-Za-z0-9]{64}`.
   - Lo atiende `PublicStoreController::show`, con el mismo shell Blade, más estas cabeceras: `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, `X-Robots-Tag: noindex` y `Content-Security-Policy: frame-ancestors 'none'`.
   - Con la tienda apagada responde 404.
   - El token no se valida al pintar la página, igual que en el personal.
2. **En la tienda,** `takeResetToken()` (gemelo de `takeReturnParam()`):
   - Lee el token de la ruta y hace `replaceState` a `/t/{slug}`.
   - Abre el panel en `authMode = 'reset'`: contraseña nueva, confirmación y el botón «Guardar contraseña».
   - El token queda sólo en memoria. Si se recarga la página, se vuelve a abrir el enlace del correo, que sigue sirviendo mientras no se use.
3. **Guardar** llama `POST /t/{slug}/password/reset` (`public.store.password.update`) con `{token, password, password_confirmation}`. Pasa por `ResetCustomerPasswordRequest` y llega a `CustomerPasswordResets::reset($token, $password)`, que corre en una transacción:
   1. Busca la fila por `token_hash = sha256(token)` con `lockForUpdate`, **bajo el scope del negocio que fijó el slug**. El token de otra tienda no existe aquí.
   2. Exige cuatro cosas: `expires_at > now`, el cliente `active`, que tenga contraseña y que `customers.email = customer_password_resets.email`. Si el correo cambió después de emitirlo, el enlace muere.
   3. Guarda la contraseña con el cast `hashed`, **borra la fila** y asienta `CUSTOMER_PASSWORD_RESET`.
   - **Cualquier falla** responde 422 con un solo mensaje en `title`: «Este enlace ya no sirve: venció, ya se usó o se pidió otro. Pide uno nuevo.». El panel ofrece «Pedir otro enlace», que lleva a `forgot`.
   - **Si sale bien** responde 200 con `{"data":{"message":"Contraseña actualizada: entra con la nueva."}}`. El panel pasa a «Iniciar sesión» con ese aviso. **No inicia sesión:** un enlace robado no se convierte en una sesión abierta (D373).
4. **Las demás sesiones** de ese cliente caen en su siguiente petición (flujo C).
5. **Arreglo de `api()`:** si la respuesta trae `payload.errors`, el error muestra su primer mensaje. Esto también arregla el registro, que hoy esconde el «mínimo 8».

### C. Cliente: la sesión se revisa (huella)

Nueva clase `Ecommerce/Http/Support/CustomerSession` (final):

- **`start(Request, Customer)`:** hace el `login` del guardia `customer`, regenera la sesión y guarda la huella en `password_hash_customer`. La usan el registro y el acceso.
- **`current(Request): ?Customer`:**
  - Sin cliente en la sesión, devuelve `null`.
  - Si la sesión no tiene huella (es de antes del despliegue), adopta la vigente, como `AuthenticateSession`.
  - Si la huella no coincide **o el cliente está inactivo**, hace `Auth::guard('customer')->logoutCurrentDevice()`, olvida la huella y devuelve `null`.
  - **Nunca** llama `flush()` ni `invalidate()`, porque la cookie es la misma que la del personal y el carrito vive en esa sesión.
  - La usan `CustomerAuthController::me` y `CheckoutController::requireCustomer`, que hoy son los dos únicos lectores del guardia. La tienda ya sabe qué hacer: `me` en `null`, el evento `session-lost` y el 401 del checkout.
- **`end(Request)`:** es el `logout` actual, sin cambiar su comportamiento (ver «Fuera de alcance»).
- **Por qué no es middleware:** la sesión del cliente sólo se puede leer **después** de `resolveStore`; sin contexto, `TenantScope` lanza.
- **Formato de la huella:** el mismo que `password_hash_web` (`SessionGuard::hashPasswordForCookie`, un HMAC con la llave de la app).
  - La comparación sale de `AuthenticateWebSession` a una pieza del kernel, `Shared/Http/Support/SessionPasswordFingerprint` (`hash`, `store` y `matches`).
  - La usan `AuthenticateWebSession` (sin cambio de comportamiento), `EnsureSuperAdmin` y `CustomerSession`.
- **El acceso** pasa a `attempt([... 'status' => 'active'])`, con el mismo mensaje genérico «Correo o contraseña incorrectos.» (decisión 3).
- **Candado nuevo:** `Auth::guard('customer')` sólo puede aparecer en `CustomerSession`.

### D. Cliente: el personal le cambia el correo o lo inactiva (decisión 5)

- **En `CustomerController::update`:**
  - Si cambió `email` y el cliente tiene credenciales:
    - llama `CustomerPasswordResets::revokeFor($customer)`;
    - despacha `Customers/Jobs/NotifyCustomerEmailChanged(tenantId, customerId, oldEmail)`.
  - Si pasa a `inactive`, también llama `revokeFor`. Sus sesiones caen solas en `current()`.
- **El aviso** (`Customers/Mail/CustomerEmailChangedMail`, con `TenantMailer`) va al correo **anterior**:
  - Dice que el correo de su cuenta en {negocio} cambió y a cuál, enmascarado (`j***@g***.com`), y que si no lo pidió se comunique con el negocio.
  - El correo nuevo va enmascarado para no regalárselo a un desconocido si el anterior estaba mal escrito.
- **`SaveCustomerRequest`** gana `$unico('email')`, acotado al negocio y con `ignore` del propio cliente. Responde 422 «Ese correo ya es de otro cliente» en lugar de reventar contra el índice.
- **`CustomerResource`** gana `has_store_account` (bool). Es `password !== null`, dato de Customers.
- **El formulario de cliente** de la administración muestra un aviso al editar el correo de un cliente con cuenta: «Este cliente entra a la tienda con este correo. Si lo cambias, su enlace de recuperación deja de servir y le avisamos al correo anterior.».
- **La bitácora** ya guarda el cambio: `CUSTOMER_UPDATED` registra el antes y el después del correo con el actor real.

### E. Plataforma: «Mi cuenta»

- **Dónde:** un enlace «Mi cuenta» junto a «Salir» en `PlatformLayout.vue`.
- **`GET /plataforma/mi-cuenta`** (`platform.account`) va a `PlatformAccountController@show`, que pinta `Pages/Platform/Account.vue`:
  - nombre, correo y último acceso;
  - contraseña actual, nueva y confirmación.
- **`PUT /plataforma/mi-cuenta/contrasena`** (`platform.account.password`) pasa por `ChangePlatformPasswordRequest`:
  - `current_password`: `required|current_password:platform`.
  - `password`: `required|string|min:{ChangePasswordRequest::MIN_LENGTH}|max:255|confirmed|different:current_password`.
  - Límite: 5 por minuto por admin, con la llave `platform-password:{ulid}`.
- Las dos rutas van detrás de `EnsureSuperAdmin`.
- **`Platform/Application/PlatformPasswords::change($admin, $new, via: 'panel')`:**
  - guarda la contraseña con el cast `hashed` y rota `remember_token`;
  - deja la constancia `platform.password_changed`;
  - encola `PlatformPasswordChangedMail`.
- **Sesiones:** la actual sigue, porque guarda la huella nueva al terminar la petición, como en D373. Las demás caen.
- Al terminar regresa con `flash.success`: «Contraseña actualizada. Tus otras sesiones se cerraron.».

### F. Plataforma: recuperar por consola

- **`comandia:platform-admin {email} {--name=}` sólo crea.** Si el correo ya existe, falla y remite al comando de abajo.
- **`comandia:platform-admin:password {email}` sólo cambia.** Si el correo no existe, falla y no crea nada. Se acaba el super admin huérfano por un error de dedo, y cambiar la contraseña ya no pisa el nombre.
- **La contraseña se pide sólo con `secret()`, dos veces,** con mínimo de 10.
  - Se retira `--password`. Sólo lo usa `tests/Feature/Platform/PlatformTest.php`, que pasa a `expectsQuestion`.
  - Si algún día hace falta automatizar, la contraseña se lee de STDIN, nunca de una opción.
- **Al cambiar,** el comando llama `PlatformPasswords::change($admin, $new, via: 'consola')`: rota `remember_token`, cierra las sesiones, deja constancia y manda el aviso.
- **`Platform/Login.vue`** muestra los mensajes `flash` (hoy no los muestra) y la nota «¿Olvidaste tu contraseña? La restablece quien opera el servidor de Comandia.».

### G. Plataforma: las demás sesiones caen

- **`EnsureSuperAdmin`,** después de `check()`, compara la huella con `SessionPasswordFingerprint`:
  - en la llave `password_hash_platform`, y también la del cifrado que lleva la cookie de «mantener la sesión abierta»;
  - al terminar la petición guarda la vigente.
- **Si no coincide:**
  - `logoutCurrentDevice()`;
  - `invalidate()`, que aquí sí se puede porque la cookie `_plataforma` es propia;
  - redirige a `platform.login` con «Tu sesión se cerró porque la contraseña cambió. Entra con la nueva.».
- **`PlatformLoginController::store`** guarda la huella al entrar.
- **Rotar `remember_token`** invalida además toda cookie de «Mantener la sesión abierta» emitida antes.
- **No se toca el grupo `platform`** ni `AuthenticateWebSession`: la revisión vive en Platform y el kernel no aprende nombres de rutas de Platform.

## Esquema

### `customer_password_resets` (nueva, módulo Customers, de negocio)

| Columna | Tipo | Por qué |
|---|---|---|
| `id` | BIGINT PK | — |
| `tenant_id` | BIGINT FK `tenants`, `ON DELETE CASCADE`, NOT NULL | ADR-002 |
| `customer_id` | BIGINT FK `customers`, `ON DELETE CASCADE`, NOT NULL | Si el cliente se borra, el enlace no tiene a quién servir |
| `email` | VARCHAR(120) NOT NULL | El buzón al que se mandó, del mismo largo que `customers.email`. Si el correo del cliente cambia por cualquier camino, el enlace muere aunque alguien olvide llamar `revokeFor` |
| `token_hash` | CHAR(64) `ascii_bin` NOT NULL | SHA-256 del token: el token en claro sólo existe en el correo |
| `expires_at` | TIMESTAMP NOT NULL | `created_at` + 60 minutos |
| `created_at` | TIMESTAMP NOT NULL | Para el enfriamiento de 5 minutos |

**Índices:**
- **`unique(customer_id)`:** un enlace vivo por cliente, impuesto por la base. Sustituye al lock de aplicación de la invitación. Además resuelve `issue` y `revokeFor`, y sirve a su FK.
- **`unique(token_hash)`:** restablecer busca por el token, con el `tenant_id` del scope como filtro extra.
- **El índice que MySQL exige a la FK de `tenant_id`:** no hay índice compuesto propio porque ninguna consulta recorre la tabla por negocio. Sirve a la cascada al borrar el negocio y a la purga del demo.

**Constraint:** `CHECK (expires_at > created_at)`.

**Lo que no lleva, a propósito:**
- **Sin `ulid`:** la fila nunca sale del servidor; su única llave pública es el token, que se guarda como hash.
- **Sin `updated_at`:** la fila no se edita; se reemplaza o se borra. No es inmutable (no entra en `ImmutableTablesTest`).
- **Sin tarea de limpieza:** hay a lo más una fila por cliente, se reemplaza al pedir otra, se borra al usarse y se revoca al cambiar el correo. Una fila vencida es un hash inservible cuyo `email` es el mismo que ya está en `customers`.

**Modelo:** `Customers/Infrastructure/Models/CustomerPasswordReset extends DomainModel`, con `UPDATED_AT = null`.

**Servicio `Customers/Application/CustomerPasswordResets`** (final), con `MINUTES_VALID = 60` y `COOLDOWN_SECONDS = 300`:
- **`issue(Customer): ?string`:**
  - En una transacción, bloquea la fila del cliente.
  - Si hay un enlace con menos de 5 minutos, devuelve `null`.
  - Si no, borra el anterior e inserta uno nuevo (`Str::random(64)`, se guarda su SHA-256) y devuelve el token.
- **`reset(string $token, string $password): Customer`:** lanza `CustomerPasswordResetInvalid`, que se traduce al 422 único.
- **`revokeFor(Customer): void`.**
- **No emite eventos de dominio:** nadie fuera de Customers escucha.

### Lo que no cambia

- **`customers`:** no gana `remember_token` ni `email_verified_at`. `Customer` **no** implementa `CanResetPassword`, porque no usa el broker.
- **`platform_admins`, `password_reset_tokens` y el broker `users`.** No se agrega ningún broker a `config/auth.php`.

### Configuración

`config/logging.php` gana el canal `platform`:
- driver `daily`, en `storage/logs/plataforma.log`;
- `max_files` = 400, que cubre los 12 meses de D47;
- `level` = `info`.

## Endpoints

| Método | Ruta | Nombre | Entrada | Salida |
|---|---|---|---|---|
| POST | `/t/{slug}/password/forgot` | `public.store.password.email` | `ForgotCustomerPasswordRequest` | 202 con un mensaje |
| GET | `/t/{slug}/restablecer/{token}` | `public.store.password.reset` | — | Shell de la tienda, con cabeceras |
| POST | `/t/{slug}/password/reset` | `public.store.password.update` | `ResetCustomerPasswordRequest` | 200 con un mensaje, o 422 |
| GET | `/plataforma/mi-cuenta` | `platform.account` | — | Inertia `Platform/Account` |
| PUT | `/plataforma/mi-cuenta/contrasena` | `platform.account.password` | `ChangePlatformPasswordRequest` | Redirección con `flash` |

- Las respuestas públicas son `JsonResponse` con un mensaje, como el resto de `/t/{slug}`; no exponen ninguna entidad, así que no llevan Resource. `/plataforma` pinta arreglos en Inertia, como sus demás pantallas.
- Cambian también `/t/{slug}/login`, `/register` y `/me` (por la huella, el estado y los límites) y `PATCH /api/v1/customers/{customer}` (unicidad del correo, revocación y aviso; `has_store_account` en el Resource).

## Permisos y bitácora

**Permisos:** no hay permisos nuevos.

| Acción | Quién |
|---|---|
| Pedir el enlace y restablecer en la tienda | Públicas, con límites (como el acceso) |
| «Mi cuenta» de plataforma | Ser super admin (`EnsureSuperAdmin`) |
| Cambiar el correo o el estado de un cliente | `customers.customers.manage`, como hoy |

`RoutePermissionTest` y `EveryEndpointIsExercisedTest` sólo miran `/api/v1`: la ruta de clientes que cambia ya está cubierta.

**Bitácora:**
- **Acción nueva `AuditAction::CUSTOMER_PASSWORD_RESET = 'customers.password_reset'`,** con la etiqueta «Se restableció la contraseña de la tienda con el enlace del correo».
  - Va en el negocio del cliente y es sobre el cliente (con su `auditable_ulid`).
  - No tiene actor: se ve como «Sistema».
  - `after` = `{via: 'enlace_correo'}`. La IP y el navegador los pone `AuditLogger`.
- **Pedir el enlace no se asienta,** como en D373: sería ruido, y un correo inexistente no tiene a quién atribuirse.
- **El cambio de correo** ya queda con `CUSTOMER_UPDATED`, con el actor real.
- **Plataforma, en el canal `platform`** (decisión 9). Los eventos son `platform.admin_created` (consola) y `platform.password_changed` (`via` = panel o consola).
  - Llevan el ULID del admin, la fecha en UTC, la IP y el navegador (panel) o el usuario del sistema operativo si se puede leer (consola).
  - **Nunca** la contraseña ni un token.

**Correos y colas** (todos en `default`):

| Correo | Clase | Remitente | Quién lo dispara |
|---|---|---|---|
| Enlace para el cliente | `Ecommerce/Mail/CustomerPasswordResetMail` | El negocio (`TenantMailer`); sin correo configurado, Comandia con el nombre «{tienda} (vía Comandia)» | Trabajo `SendCustomerPasswordResetLink` |
| Aviso de cambio de correo | `Customers/Mail/CustomerEmailChangedMail` | El negocio, con el mismo respaldo | Trabajo `NotifyCustomerEmailChanged` |
| Aviso de contraseña de plataforma | `Platform/Mail/PlatformPasswordChangedMail` (`ShouldQueue`) | Comandia (`MAIL_FROM_ADDRESS`) | `PlatformPasswords::change` |

**Dependencias entre módulos** (`ModuleBoundariesTest`):
- Ecommerce usa Customers, lo cual está permitido.
- Customers usa Configuration (`TenantMailer`) y Tenancy, que son del kernel. No importa nada de Ecommerce.
- Platform usa Identity (`ChangePasswordRequest::MIN_LENGTH`) y Shared, también del kernel.

## Seguridad

| Amenaza | Qué la cierra |
|---|---|
| **Enumeración** («¿este correo es cliente de este bar?», «¿es super admin?») | Cliente: mismo texto y mismo 202 siempre; la búsqueda ocurre en el trabajo y el limitador cuenta todo pedido, exista o no la cuenta. Plataforma: no hay endpoint público. **Riesgo residual declarado:** el registro sigue diciendo si un correo o un teléfono ya tiene cuenta, ahora con límite |
| **Fuerza bruta** | Token de 64 caracteres alfanuméricos aleatorios, guardado como SHA-256, de un uso y con 60 minutos. Restablecer: 5 por minuto por IP. Acceso del cliente: 5 por minuto por negocio+correo+IP (hoy son 300 por IP) |
| **Robo de sesión** | Cliente: huella revisada en cada lectura del guardia. Plataforma: huella en `EnsureSuperAdmin` y `remember_token` rotado. Restablecer no inicia sesión |
| **Envenenamiento o fuga del enlace** | Siempre `config('app.url')` y el slug leído de la base. El correo del cliente no va en el enlace. La página de restablecer lleva `no-referrer`, `no-store`, `noindex` y `frame-ancestors 'none'`. La tienda quita el token de la barra. **Residual:** el token en la ruta llega a los logs del servidor web, acotado por un uso y 60 minutos |
| **Recuperación como puerta trasera** | Cliente: el enlace sólo vale si el correo del cliente **sigue siendo** al que se mandó; cambiar el correo lo revoca, avisa al anterior y queda en la bitácora con el actor. Plataforma: sin autoservicio público; la consola separa crear de cambiar; cada cambio avisa al admin y deja constancia |
| **Aislamiento entre negocios** | Tabla con `tenant_id` y `TenantScope`. El token de A presentado en `/t/{slugB}` no existe. El correo sale con el SMTP de A y la bitácora va sólo a A |
| **Choque de identidades** (personal, admin y cliente con el mismo correo) | Cada guardia tiene su mecanismo. `password_reset_tokens` queda sólo para el personal |
| **Bombardeo de correos y reputación del SMTP del negocio** | 3 cada 15 minutos por negocio+correo+IP, 20 por hora por IP y enfriamiento de 5 minutos por cliente en el trabajo |

**Límites y vigencias:**

| | Cliente | Plataforma |
|---|---|---|
| Vigencia del enlace | 60 min, un uso, uno vivo por cliente | Sin enlace |
| Pedir el enlace | 3 cada 15 min por negocio+correo+IP (`customer-forgot:`); 20 por hora por IP (`customer-forgot-ip:`); enfriamiento de 5 min | — |
| Restablecer | 5 por minuto por IP (`customer-reset:`) | — |
| Acceso | **Nuevo:** 5 por minuto por negocio+correo+IP; cuenta los fallos y se limpia al entrar (patrón de `PlatformLoginRequest`) | 5 por minuto, como ya está |
| Registro | **Nuevo:** 10 por hora por IP | — |
| Cambiar la contraseña con la sesión abierta | — | 5 por minuto por admin |
| Mínimo de caracteres | 8 (como el registro) | 10 (como el personal) |

Todo 429 lleva su mensaje en `title`, con los minutos de espera, y se calcula igual exista o no la cuenta.

## App Flutter

No cambia nada. La app es sólo del personal: no toca `/t/{slug}` ni `/plataforma`, y ningún endpoint de `/api/v1` que use cambia de forma. El «¿Olvidaste tu contraseña?» del personal en la app es un pendiente aparte.

## Pruebas

Son pruebas Pest con títulos en español, como `tests/Feature/Identity/PasswordTest.php`.

**`tests/Feature/Ecommerce/CustomerPasswordResetTest.php`**
- **Pedir el enlace:**
  - Mismo 202 y mismo texto para cuatro casos: cliente con cuenta, correo inexistente, cliente del POS con correo y sin contraseña, y cliente inactivo.
  - El trabajo se encola siempre.
- **El trabajo,** probado con `forget()` + `dispatch_sync`, porque en la cola `sync` hereda el contexto:
  - Sólo el primer caso manda correo, y lo manda con el `TenantMailer` del negocio.
  - El enlace usa `app.url` y el slug de la base aunque la petición llegue con otro `Host`.
  - Si el slug cambió entre la petición y el trabajo, usa el nuevo.
  - Dos pedidos dentro del enfriamiento mandan un solo correo.
  - Una falla del SMTP no deja un token vivo.
  - No envía si la tienda se apagó antes de que corriera.
- **Restablecer, casos de error:** responden el mismo 422 un token:
  - vencido (avanzar 61 minutos);
  - usado dos veces;
  - reemplazado por otro;
  - emitido antes de que cambiara el correo;
  - emitido antes de inactivar al cliente;
  - mal formado;
  - presentado en otra tienda.
- **Restablecer, éxito:**
  - `Hash::check` pasa con la nueva; la vieja no entra y la nueva sí.
  - La fila se borra y queda el asiento `CUSTOMER_PASSWORD_RESET` sin actor.
  - **No inicia sesión:** `me` responde `null`.
- **Sesiones:**
  - Otra sesión del mismo cliente recibe `null` en `me` y 401 en el checkout.
  - Una sesión de antes del despliegue, sin huella, sigue funcionando.
  - La sesión del personal en el mismo navegador sigue viva, y el carrito también.
- **Aislamiento** (test del módulo):
  - El mismo correo con cuenta en A y en B: pedir en A crea fila y correo sólo en A; B queda intacto.
  - El token de A en `/t/{slugB}/password/reset` falla y B no cambia.
  - La bitácora de B no recibe nada.
  - Un correo que es a la vez de personal y de cliente: ningún flujo toca `password_reset_tokens` ni la tabla del otro.
- **Límites:**
  - El cuarto pedido en 15 minutos da 429, también con un correo inexistente.
  - El sexto restablecimiento en un minuto da 429.
- Con la tienda apagada o el módulo desactivado, los dos POST y el GET dan 404.

**`tests/Feature/Ecommerce/CustomerAuthTest.php`** (se amplía)
- Un cliente inactivo no entra y recibe el mensaje genérico.
- Un cliente inactivado con la sesión abierta la pierde.
- El sexto fallo de acceso da 429, y entrar bien limpia el contador.
- El registro tiene su tope.
- Un 422 de validación llega con su mensaje en `errors`.

**`tests/Feature/Customers/`** (cambio de correo)
- Cambiar el correo de un cliente con cuenta revoca su enlace, encola el aviso al correo anterior con el nuevo enmascarado y deja `CUSTOMER_UPDATED` con el actor.
- Un correo duplicado da 422 y no un error de la base.
- `has_store_account` en el Resource.
- Sin el permiso, 403 (ya cubierto).

**`tests/Feature/Platform/PlatformPasswordTest.php`**
- **«Mi cuenta»:**
  - Con la contraseña actual errónea, con menos de 10 caracteres o igual a la actual, no cambia.
  - Si sale bien: la sesión actual sigue; una segunda sesión vuelve al acceso con el aviso; una cookie de «mantener» vieja se rechaza; se encola el aviso y queda la constancia (`Log::spy` sobre el canal).
  - El sexto intento en un minuto da 429.
- **Consola:**
  - Cambiar a alguien inexistente falla sin crear nada; crear a alguien existente falla.
  - Se respeta el mínimo de 10.
  - Rota `remember_token` y una sesión abierta cae en su siguiente petición.
  - Ya no existe `--password`.
  - Cambiar la contraseña no pisa el nombre.
- **Regresión de D372:** la sesión `web` del personal no se afecta y `AuthenticateWebSession` se comporta igual después de extraer `SessionPasswordFingerprint`.

**Candados**
- `TenantScopeTest`: el modelo nuevo es un `DomainModel`.
- `AuthorizationDisciplineTest`: **sin excepciones nuevas**.
- `DemoPurgeCoversTenantTablesTest`: la tabla cascadea de `tenants` y de `customers` y no tiene FK restrictivas.
- `ModuleBoundariesTest`: Customers no importa a Ecommerce.
- Candado nuevo: `Auth::guard('customer')` sólo aparece en `CustomerSession`.

**Romper a propósito:** cada cambio de esta lista tiene que hacer fallar al menos una prueba:
- quitar `whereNotNull('password')` o `active()` del trabajo;
- quitar la comparación de correo en `reset`;
- armar el enlace con `url()`;
- quitar la comparación de huella en `CustomerSession::current` o en `EnsureSuperAdmin`;
- no rotar `remember_token`;
- quitar `revokeFor` del cambio de correo;
- quitar el `where` de `status` en el acceso.

**Antes de cada commit:**
- La suite completa, en paralelo y la serie.
- Verificación en el navegador:
  - los modos `forgot` y `reset` de la tienda con el enlace real y a ancho de teléfono;
  - «Mi cuenta» de plataforma;
  - el aviso del formulario de cliente.

## Fases de implementación

Cada fase se prueba y se entrega por separado.

1. **Sesiones y límites del cliente:**
   - `SessionPasswordFingerprint` en el kernel (`AuthenticateWebSession` la adopta sin cambiar su comportamiento);
   - `CustomerSession` y su candado;
   - el acceso rechaza a los inactivos;
   - los límites del acceso y del registro;
   - el arreglo de `api()`.
2. **Recuperación del cliente:**
   - la tabla, el modelo y el servicio;
   - el trabajo y el correo;
   - las rutas y el shell con cabeceras;
   - los modos `forgot` y `reset` de la tienda;
   - la acción de bitácora;
   - la contención del cambio de correo (unicidad, revocación, aviso, `has_store_account` y el aviso del formulario).
3. **Plataforma:**
   - «Mi cuenta»;
   - la huella en `EnsureSuperAdmin` y en el acceso;
   - la consola dividida y sin `--password`;
   - `PlatformPasswords`, el aviso por correo y el canal `platform`;
   - la nota y los `flash` en el acceso.

## Documentación

- **`docs/REGISTRO_DECISIONES.md`:**
  - Registrar D381 (clientes, que cierra la parte de recuperación de la deuda de D333) y D382 (plataforma).
  - Cerrar el renglón del pendiente.
  - Abrir como pendientes:
    - el registro enumera;
    - la activación por teléfono;
    - el `logout` del cliente invalida la sesión compartida;
    - el mínimo de 8 en `StoreBusinessRequest`.
- **`.module.md`:**
  - **Customers:** la tabla, el servicio, el trabajo de aviso y la unicidad del correo.
  - **Ecommerce:** las rutas, `CustomerSession`, el trabajo y el correo. No se emiten eventos de dominio nuevos.
  - **Shared:** `SessionPasswordFingerprint`.
  - **Audit:** la acción nueva.
  - **Crear el de Platform**, que no existe: guardia, rutas, consola, canal de log y huella.
- El docblock de `CustomerAuthController` deja de declarar la deuda de recuperación (conserva la de verificación de correo).
- Al aprobarse, este diseño queda en `docs/iteraciones/DISENO-recuperacion-plataforma-y-clientes.md`.
- **Ninguna parte contradice una ADR.** La alternativa (c) de la decisión 2 sí exigiría una nueva.

**Riesgos que quedan:**
- Quien controla el buzón de un cliente controla su cuenta: es inherente a recuperar por correo.
- Quien administra el SMTP del negocio ve los enlaces; pero ese negocio ya puede cambiar el correo del cliente.
- Si cambia el slug, un correo ya enviado deja de servir. Con la tienda apagada no hay recuperación.
- **Con la opción B,** si un admin pierde la contraseña y el acceso al servidor a la vez, sólo lo rescata otro admin con acceso al servidor.
- La constancia de plataforma es un archivo que puede editar quien opera el servidor. Tiene que entrar en el respaldo del servidor (D57).

## Decisiones para el dueño

1. **¿Cómo recupera su contraseña un super administrador?**
   - **A.** «¿Olvidaste tu contraseña?» público por correo, igual que el personal:
     - Broker `platform_admins` con tabla propia `platform_password_reset_tokens`, con la forma de Laravel y sin `tenant_id`, de plataforma como `platform_admins`. Vigencia de 30 minutos y `auth:clear-resets platform_admins` diario.
     - Rutas `/plataforma/olvide-contrasena` y `/plataforma/restablecer/{token}`.
     - El enlace se arma con `app.url` en el *callback* de `sendResetLink`, y `PlatformAdmin::sendPasswordResetNotification` lanza `LogicException`, porque la heredada apunta a la pantalla del personal.
     - Suma también todo lo de B.
     - Es autoservicio, pero deja la cuenta que crea y suspende negocios igual de segura que un buzón, y abre otra superficie pública.
   - **B. (recomendada)** Consola endurecida, más «Mi cuenta», el cierre de sesiones, el aviso por correo y la constancia (flujos E–G).
     - Exige acceso al servidor (D57), un factor mucho más fuerte que un buzón.
     - Cero endpoints públicos nuevos y cero tablas.
     - Variante más chica: B sin «Mi cuenta». El cambio queda sólo por consola.
   - **C.** Otro super admin dispara el enlace desde el panel. Exige una pantalla de super admins que no existe, y con uno o dos admins no se justifica.
   - Revisar esta decisión **cuando llegue 2FA**: entonces la opción A, pidiendo el código TOTP al restablecer.

2. **¿Dónde viven los tokens del cliente?**
   - **(a) (recomendada)** Una tabla del negocio, `customer_password_resets`, con `tenant_id`:
     - Respeta ADR-002 sin excepciones y se busca bajo el contexto del slug.
     - Un enlace vivo por cliente, impuesto por la base.
     - El enlace muere si cambia el correo.
     - El token nace en el trabajo y no viaja en la cola.
     - Es el patrón de `membership_invitations` (D374).
   - **(b)** El broker de Laravel con driver `cache` y la llave = ULID del cliente (`getEmailForPasswordReset`):
     - No hay esquema y vence solo.
     - Pero un `cache:clear` o el desalojo de Redis tira los enlaces pendientes, no se pueden inspeccionar y el token en claro viaja en la carga útil del trabajo.
     - Obliga a `Customer implements CanResetPassword`, y el filtro «con contraseña y activo» tiene que ir como *closure* en una llave que no contenga «password», porque `EloquentUserProvider` descarta esas llaves sin avisar.
   - **(c)** El broker con una tabla de plataforma sin `tenant_id`. Es una excepción a ADR-002 que exige una ADR nueva, y deja una columna `email` guardando un ULID.

3. **¿A qué clientes se les manda el enlace?**
   - **(a) (recomendada)** Sólo a clientes `active` con contraseña. Además, el acceso rechaza a los inactivos y la sesión de un inactivo cae. Hoy un inactivo entra a la tienda.
   - **(b)** Incluir a los clientes del POS con correo y sin contraseña, para activar la cuenta por correo. Ese correo lo tecleó el personal y nunca se verificó: uno mal escrito le entregaría el expediente a un extraño.
   - **(c)** Como hoy: los inactivos entran y también recuperan.

4. **¿De qué correo sale el enlace del cliente?**
   - **(a) (recomendada)** Del negocio, con `TenantMailer` en un trabajo, y de Comandia con el nombre de la tienda si el negocio no configuró el suyo. El cliente conoce al restaurante, no a Comandia; es el criterio de la invitación (D374) y lo que anticipó D333.
   - **(b)** Siempre de Comandia.

5. **El personal cambia el correo de un cliente con cuenta.**
   - **(a) (recomendada)** Permitirlo con contención:
     - se revoca el enlace vivo;
     - se avisa al correo anterior, con el nuevo enmascarado;
     - el formulario valida que el correo sea único y advierte que el cliente tiene cuenta;
     - el rastro con el actor ya existe (`CUSTOMER_UPDATED`).
     El aviso es lo único que le avisa a la víctima.
   - **(b)** La misma contención sin el aviso al correo anterior: es más barata y sólo deja el rastro en la bitácora.
   - **(c)** Bloquear la edición del correo de un cliente con cuenta. Un correo mal escrito dejaría la cuenta sin salida, porque su teléfono ya tiene cuenta y no se puede registrar otra.

6. **¿Restablecer cierra las demás sesiones del cliente?**
   - **(a) (recomendada)** Sí, con la huella en la sesión (`CustomerSession`, flujo C). Es la decisión 3 de D373 aplicada al cliente; sin ella, una sesión robada sigue viva mientras se use.
   - **(b)** No. Se ahorra la clase y el candado, y queda como deuda declarada.

7. **Longitud mínima de la contraseña.**
   - **(a) (recomendada)** Plataforma 10, como el personal: la consola sube de 8 a 10 y «Mi cuenta» exige 10. Cliente 8, igual en el registro y al restablecer: es una cuenta de consumo y la fricción en el checkout cuenta.
   - **(b)** 10 en todo, cambiando también el registro de clientes.

8. **¿Se suman a esta entrega los límites del acceso y del registro del cliente?**
   - **(a) (recomendada)** Sí, en la fase 1. Recuperar no protege nada si el acceso admite 300 intentos por minuto, y lo pide ARQUITECTURA §8 (D55).
   - **(b)** Dejarlo como pendiente aparte.

9. **¿Dónde queda la constancia de lo que hace un super admin con su contraseña?** `audit_entries` exige `tenant_id`.
   - **(a) (recomendada)** Un canal de log `platform`, diario, con 400 archivos (al menos los 12 meses de D47), sin esquema y dentro del respaldo del servidor.
   - **(b)** Una tabla `platform_audit_entries`. Conviene cuando se audite todo lo de plataforma (alta y suspensión de negocios), que hoy tampoco deja asiento.
   - **(c)** Nada.
