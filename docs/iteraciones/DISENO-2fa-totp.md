# Diseño: verificación en dos pasos (2FA TOTP)

> Estado: **propuesto, pendiente de aprobación.**
>
> - **Qué cierra:** la fila «2FA TOTP (ARQUITECTURA §10.2)» de «Pendiente de diseño abierto» en `docs/REGISTRO_DECISIONES.md`.
> - **Qué cumple:**
>   - ARQUITECTURA §10.2: «2FA TOTP opcional, obligable por tenant para roles administrativos».
>   - §10.4: secretos con cast `encrypted`.
>   - D54: «Sanctum + 2FA opcional + PIN con bloqueo».
> - **ADR:** no contradice ninguna. La única alternativa que lo haría es la 12(b), y está marcada.
> - **Mecanismos de D372–D374 que reusa:**
>   - `AccountAudit` para los hechos de la cuenta.
>   - `AppSessions` para cerrar las sesiones de la app.
>   - El cifrado de la contraseña para cerrar los navegadores.
>   - Correo por cola, enviado desde la plataforma, con enlaces armados sobre `app.url`.

## Problema

### Lo que hay hoy

**Ninguna lógica de 2FA.** Sólo existen piezas sueltas que nada usa:

- **Columnas en `users`:**
  - `two_factor_secret`, TEXT con cast `encrypted`.
  - `two_factor_recovery_codes`, TEXT con cast `encrypted`. Su formato nunca se definió.
  - `two_factor_confirmed_at`.
- **`User::hasTwoFactorEnabled()`:** existe y nadie lo llama.
- **`roles.requires_two_factor`:** las plantillas lo siembran en `true` para Propietario y Gerente. Nadie lo lee.
- **El ajuste `security.require_two_factor_for_admin_roles`:**
  - Default `false` y `enforced: false`.
  - Está fuera del panel por D351 y D363.
- **`AuditAction::TWO_FACTOR_ENABLED`:** sin uso.
- **Librerías:** no hay ninguna de TOTP ni de QR en `composer.json`, `package.json` ni `pubspec.yaml`.

**La cuenta es de la plataforma y la obligación sería del negocio.** El secreto es uno por cuenta, válido en todos sus negocios. La obligación depende del ajuste de cada negocio y del **rol activo**, que cambia en cada petición con `X-Role` (D9). Hoy ningún punto evalúa esa combinación. Donde ya se cruzan negocio, rol y cuenta es `ResolveTenantContext`.

**Cuatro caminos crean una credencial sin ningún paso intermedio:**

1. `LoginController::store`: `Auth::attempt` deja la sesión autenticada antes de cualquier otra cosa.
2. `InvitationAcceptanceController::store` con una cuenta que ya existe: hace `Hash::check` y luego `login`, dentro de una transacción.
3. La cookie «Mantener la sesión abierta». Dura **400 días** (`SessionGuard::$rememberDuration = 576000` minutos).
4. `POST auth/token`.

A eso se suma `PlatformLoginController`, en su propio guardia.

**Cerrar los navegadores de una cuenta exige cambiar el cifrado de su contraseña.** Lo comparan `AuthenticateWebSession` y el `AuthenticateSession` de Sanctum, y para volver a cifrar hace falta la contraseña en claro.

**`auth/token` revela los negocios a quien sólo sabe la contraseña.** El 409 con `memberships` sale antes de cualquier otro paso.

### Hallazgos al leer el código (verificados en el repositorio)

1. **El kiosco llega a administración con el rol del operador.**
   - `SharedTerminalResolver` le asigna su `defaultRole`.
   - En `SharedTerminalTest`, lo único que frena a Mesero en `/api/v1/memberships` es que su rol no tiene el permiso.
   - ADR-012, regla 1: el token de dispositivo «no puede ejecutar nada fuera de operar el POS», y su prueba es que «contra una ruta de administración recibe 403».
   - Consecuencia: con el código de empleado y el PIN de un Gerente se entra a administración sin contraseña y, cuando exista, sin segundo factor.
   - No lo ejecuté; lo deduje leyendo el código.
2. **`auth/token` deja averiguar si un correo existe midiendo el tiempo de respuesta.** La condición `$user === null || ! Hash::check(...)` no calcula el hash cuando el correo no existe, así que responde más rápido. `SessionGuard::validate()` sí iguala el tiempo con `Timebox`.
3. **La app no asienta `LOGIN` ni `LOGIN_FAILED`.** El navegador sí los asienta.
4. **La bandera de 2FA de los roles no se ve ni se audita** (contra D360):
   - `RoleController` no incluye `requires_two_factor` en el before/after de `ROLE_CREATED` ni de `ROLE_UPDATED`.
   - `Admin/Roles/Index.vue` no la muestra.
5. **`ApiProblem` no puede conocer una excepción de Identity.** Vive en el kernel (`Shared`), y el kernel no depende de ningún módulo. Para un tipo de problema nuevo hace falta una interfaz en el kernel, no una referencia a la clase del módulo.

### La idea

**La invariante:** si una cuenta tiene la verificación activa, toda sesión y todo token vivos de esa cuenta nacieron con el segundo factor, o son la sesión desde la que se activó.

**Tres piezas la sostienen:**

1. **Una sola puerta,** `SecondFactor`, por la que pasan todas las vías de acceso.
2. **Activar cierra todo lo demás:** navegadores, app y recordatorios, en todos los negocios.
3. **Un candado de arquitectura** que impide abrir una vía de acceso nueva sin pasar por esa puerta.

**Lo que esto ahorra:**

- No hace falta marcar las sesiones ni los tokens como «verificados».
- `personal_access_tokens` y `sessions` no cambian.
- La obligación de cada negocio sólo tiene que mirar `two_factor_confirmed_at`.

## Alcance

### Dentro

- **TOTP según el RFC 6238:** SHA-1, 6 dígitos, periodo de 30 s y un paso de margen hacia cada lado. Funciona con Google Authenticator, Microsoft Authenticator, 1Password y Authy.
- **En «Mi cuenta» (web):** activar la verificación, generar una clave de recuperación nueva y quitarla.
- **El paso del código en tres accesos:**
  - el acceso web;
  - la aceptación de una invitación con cuenta existente;
  - `POST auth/token`.
- **La recuperación:** la clave de recuperación de la propia persona y el reinicio por soporte.
- **La obligación por negocio y por rol activo:**
  - el ajuste vuelve al panel;
  - la casilla aparece en Roles;
  - el estado de cada persona aparece en Personal.
- **Avisos por correo y bitácora.**
- **App Flutter:** el paso del código y una pantalla de bloqueo cuando el rol exige la verificación.
- **Administradores de plataforma:** fase 3, si se aprueba la decisión 13.
- **Hallazgos:** se resuelven aquí los hallazgos 2 a 4 (decisión 14), y el 1 como requisito previo de la obligación (decisión 12).

### Fuera de alcance

Las exclusiones de seguridad se declaran en el `.module.md` de Identity y en pruebas que las fijan.

- **PIN de terminal compartida y kiosco (ADR-012/014).** El PIN identifica a alguien en un dispositivo que ya enroló otra persona con sesión.
- **Autorización por PIN (ADR-008).**
- **Agentes de impresión** y **clientes de la tienda en línea** (guardia `customer`).
- **WebAuthn y llaves de acceso.** Son lo único que frena el phishing que reenvía el código en tiempo real. Quedan para una ADR futura.
- **Códigos por SMS o por correo.** Se rechazan por el robo del número (SIM swapping) y porque el correo es el mismo canal que restablece la contraseña.
- **Otros mecanismos de cuenta:**
  - volver a pedir el código antes de acciones sensibles;
  - «dispositivos de confianza» distintos de la cookie de recordatorio;
  - activar la verificación desde la app (decisión 9);
  - cambiar el correo de una cuenta.
- **Pendientes propios que no se tocan aquí:**
  - asentar `ROLE_SWITCHED`;
  - la bitácora y la recuperación de contraseña de la plataforma;
  - avisar por correo cuando alguien cambia su contraseña. Hoy no se avisa, y con este diseño queda incoherente: se anota como pendiente.

## Flujos

### A. Activar («Mi cuenta», nueva tarjeta «Verificación en dos pasos»)

1. **«Activar» llama a `POST /api/v1/me/two-factor`:**
   - Genera un secreto de 160 bits **nuevo en cada intento** y lo guarda cifrado en `two_factor_secret`, con `two_factor_confirmed_at` en NULL. Ese es el estado «pendiente».
   - Devuelve:
     - el QR como **data URI SVG** generado en el servidor, que la pantalla pinta con `<img>` y nunca con `v-html`;
     - la clave para teclearla a mano, en grupos de 4;
     - el emisor («Comandia», tomado de `config/comandia.php` y nunca del `Host`).
   - Responde con `Cache-Control: no-store`.
   - Si la verificación ya está activa, responde **409**: para cambiar de teléfono primero se quita.
   - Un secreto pendiente no protege nada ni cuenta para nada: `hasTwoFactorEnabled()` mira `confirmed_at`. El siguiente «Activar» lo reemplaza.
2. **La persona escanea el QR y escribe un código y su contraseña.** Eso va a `POST me/two-factor/confirm`, que valida la contraseña y el código contra el secreto pendiente. Después, en una transacción:
   - fija `two_factor_confirmed_at` y `two_factor_last_used_step`;
   - genera la **clave de recuperación** y guarda sólo su hash;
   - **cierra todo lo demás**, con el mismo mecanismo de `PasswordChanges` (D373):
     - vuelve a cifrar la misma contraseña con sal nueva, y así caen los demás navegadores;
     - renueva `remember_token`, y así caen las cookies de recordatorio;
     - llama a `AppSessions::revokeAllOf($user, 'two_factor', except: token actual)`, que cierra la app en todos los negocios.
   - asienta `TWO_FACTOR_ENABLED` en cada negocio con `AccountAudit::inEveryBusinessOf`;
   - encola el aviso.

   La sesión actual sigue abierta: `AuthenticateSession` guarda el cifrado nuevo al terminar la petición, igual que al cambiar la contraseña.
3. **La respuesta trae la clave de recuperación una sola vez,** con «Copiar» y «Descargar .txt» (sólo presentación) y el aviso «Guárdala en un lugar seguro; no la volverás a ver».
4. **Por qué la contraseña se pide al confirmar y no al iniciar.** Iniciar sólo deja un pendiente sin efecto. Confirmar sí tiene efecto, y pedir la contraseña ahí impide que alguien con una sesión robada active la verificación con **su** teléfono y deje fuera a la dueña.

### B. Entrar en el navegador (`POST /login`, misma ruta)

1. **Límite de contraseña:** el actual, 5 por minuto por correo e IP.
2. **Validar la contraseña sin iniciar sesión.** `VerifyCredentials` usa `guard('web')->validate()`, que iguala el tiempo de respuesta, y luego `getLastAttempted()`.
   - Si falla, todo sigue como hoy: el mismo mensaje y `LOGIN_FAILED`.
   - Si pasa, limpia el límite de contraseña y vuelve a cifrar la contraseña si hace falta, que es lo que `attempt` hacía.
3. **Sin verificación activa:** el acceso sigue como hoy.
4. **Con verificación activa:**
   - **Límite del segundo factor agotado:** «Demasiados códigos incorrectos. Vuelve a intentarlo en N minutos.».
   - **Sin `code` ni `recovery_key`:** error 422 en `code` con «Escribe el código de 6 dígitos de tu app de autenticación.». Entonces `Login.vue`:
     - muestra el paso del código, con `autocomplete="one-time-code"` y el enlace «Usar mi clave de recuperación»;
     - **no borra la contraseña** (`onFinish` no la limpia mientras dure este paso);
     - reenvía el mismo formulario.

     Vue sólo reacciona al error; no decide nada.
   - **Con código:** se llama a `SecondFactor::verify` y después a `spend`. Si el código es inválido, vencido o repetido:
     - el mensaje es siempre «El código no es válido o ya se usó.»;
     - el fallo suma al límite del segundo factor;
     - se asienta `TWO_FACTOR_FAILED`.
5. **Iniciar la sesión:** `Auth::guard('web')->login($user, $remember)`, regenerar la sesión, `last_login_at` y el `redirectAfterLogin` actual, sin cambios.
   - La cookie de recordatorio sólo nace **después** del código.
   - El aviso «tu cuenta no está activa en ningún negocio» sigue saliendo después de la contraseña y del código.
6. **Con la clave de recuperación:** la persona entra y la verificación queda desactivada (flujo F).
   - El flash dice: «Entraste con tu clave de recuperación: la verificación en dos pasos quedó desactivada. Actívala de nuevo en Mi cuenta.».
   - Si su rol activo la exige, la siguiente petición la manda a activarla.

**Cambiar de negocio** (`TenantSelectionController`) no vuelve a pedir el código: el factor es de la cuenta, y la obligación se revisa en cada petición.

### C. Aceptar una invitación con cuenta existente y sin sesión

- `AcceptInvitationRequest` gana `code` y `recovery_key`.
- **La contraseña y el factor se verifican antes de la transacción.** Si el factor falla, ni su asiento ni el límite se deshacen con el rollback.
- **El gasto del código (`spend`) va dentro de la misma transacción que acepta.** Si aceptar falla, por ejemplo por el límite de personas del plan, el código no queda gastado, pero tampoco se emitió ninguna credencial.
- Con la contraseña correcta, la verificación activa y sin código, responde 422 en `code`. `AcceptInvitation.vue` muestra entonces el campo.
- **Los otros modos no cambian:**
  - `signed_in`: esa sesión ya pasó por el código.
  - `new_account`: la cuenta todavía no tiene verificación.

### D. Entrar en la app (`POST /api/v1/auth/token`, misma ruta)

**Orden de los pasos:**

1. el límite;
2. la contraseña (`VerifyCredentials`);
3. el **segundo factor**;
4. las membresías activas;
5. `tenant_ulid`;
6. emitir el token.

Así, quien sólo tiene la contraseña no ve en qué negocios trabaja la persona.

**Respuestas:**

- **Sin código y con la verificación activa:**

  `409 {"step":"two_factor","methods":["totp","recovery_key"],"message":"…","errors":{"code":["Tu cuenta tiene verificación en dos pasos. Si no ves dónde escribir el código, actualiza Comandia."]}}`

  El campo `errors` existe para las versiones viejas de la app: hoy sólo leen `errors` o `memberships`, así que mostrarán ese texto en lugar de un error genérico.
- **Con código válido y varios negocios sin `tenant_ulid`:** el 409 de hoy, al que sólo se le **agrega** `"step":"tenant"`. Conserva `memberships`, así que las versiones viejas siguen funcionando. El código se verifica pero **no se gasta**.
- **Código inválido, vencido o repetido:** 422 en `code`. La app vuelve a pedirlo. Si pasó más de un minuto eligiendo negocio, el código ya cambió.
- **Al emitir:** `spend` e `IssueApiToken::issue` van **en la misma transacción**. Se asienta `LOGIN` con `after: {channel: "app", device}` en el negocio del token (decisión 14).

### E. La obligación por negocio y rol activo

**El ajuste.** `security.require_two_factor_for_admin_roles` pasa a `enforced: true` (D351) y vuelve al panel, con su default `false`.
- Lo gobierna `configuration.tenant.update` y lo asienta `SETTING_UPDATED`.
- Descripción nueva: «Exigir la verificación en dos pasos a quien opere con un rol marcado “Exige verificación en dos pasos” (Propietario, Gerente y los que marques en Roles). Quien no la tenga activa, incluida tú, tendrá que activarla antes de continuar.».

**Roles.**
- `Admin/Roles/Index.vue` muestra y envía la casilla «Exige verificación en dos pasos».
- En Propietario la casilla aparece fija y marcada, porque es `is_system`.
- `RoleController` agrega la bandera al before/after de `ROLE_CREATED` y `ROLE_UPDATED`.

**El middleware `Identity\Http\Middleware\EnsureTwoFactorEnrollment`.**
- **Registro:** va en los grupos `web` y `api`. En la lista de prioridad se coloca justo después de `ResolveTenantContext` y antes de `SubstituteBindings`, con una prueba que fija ese orden.
- **La regla vive en `TwoFactorRequirement::blocks(RequestContext)`.** Bloquea cuando se cumple todo esto:
  - el principal es `User`;
  - el ajuste está encendido en el negocio;
  - `activeRole->requires_two_factor` es verdadero;
  - la cuenta no tiene la verificación activa.
- **No agrega consultas:** el ajuste sale del mapa en caché de `Settings`, y el rol y la cuenta ya están cargados en el contexto.
- **Rutas que pasan aunque bloquee:**
  - `logout`, `tenants.*`, `invitation.*`;
  - `admin.account`;
  - `me.*` y `auth.token.destroy`;
  - `context.show`, que además es por donde se cambia de rol con `X-Role`;
  - las que carga el propio armazón de la pantalla: `notifications.*`, `preferences.*` y `broadcasting.auth`. Sin estas, Mi cuenta se redirigiría a sí misma en un bucle.
- **Respuestas:**
  - **API:** `TwoFactorEnrollmentRequired`, un 403 que implementa la nueva interfaz del kernel `Shared\Http\ProvidesProblemType`. `ApiProblem` la consulta antes del brazo genérico de `HttpException`. El cuerpo lleva `type: "two_factor_enrollment_required"` y el detalle «Tu rol «Gerente» exige la verificación en dos pasos en este negocio. Actívala para continuar, o cambia a otro rol.».
  - **Web:** redirige a `admin.account` con ese mismo aviso.
  - **SPA:** `resources/js/api/client.js`, al recibir ese `type`, visita Mi cuenta. Es navegación, no una regla de negocio.
- **Cambiar de rol:** con `X-Role`, a un rol sin la bandera, la persona sigue operando. Es lo que pide §10.2.
- **El operador del kiosco no lo cruza,** porque no es `User`. Se declara así y queda condicionado a la decisión 12.

**Personal.**
- `MembershipResource` gana `two_factor_enabled: bool|null`, que es `null` si la persona no tiene cuenta.
- El listado gana el filtro `two_factor` (`enabled` | `disabled`) en la lista blanca de filtros.
- Es lo que le dice al dueño a quién va a frenar antes de encender la obligación (decisión 10).

### F. Clave de recuperación, generar una nueva y quitar la verificación

**La clave.**
- Son 20 caracteres Crockford base32 en grupos de 5, unos 100 bits.
- Se normaliza: mayúsculas, sin espacios ni guiones, y con las equivalencias de Crockford (I y L valen 1; O vale 0).
- Se compara con `hash_equals` contra el SHA-256 guardado.

**Usarla al entrar** (web, app o invitación) la gasta y **desactiva la verificación** en un solo UPDATE condicionado:

```sql
UPDATE users SET two_factor_secret = NULL, two_factor_confirmed_at = NULL, two_factor_recovery_hash = NULL, two_factor_last_used_step = NULL
WHERE id = ? AND two_factor_recovery_hash = ?
```

Si el UPDATE no afecta ninguna fila, la clave es inválida. Después se asienta `TWO_FACTOR_RECOVERY_USED` en cada negocio y se manda el aviso. Quien la usa casi siempre perdió el teléfono, y lo que necesita es volver a activar la verificación con uno nuevo.

**`POST me/two-factor/recovery-key`** (contraseña y código):
- Reemplaza el hash.
- Devuelve la clave nueva una sola vez, con `no-store`.
- Asienta y avisa.
- No cierra sesiones.

Sirve a quien perdió el papel pero conserva el teléfono.

**`POST me/two-factor/disable`** (contraseña y, o código, o clave de recuperación):
- Borra el secreto, `confirmed_at`, `last_used_step` y el hash.
- Asienta `TWO_FACTOR_DISABLED` en cada negocio y avisa.
- No cierra sesiones: no abre ningún acceso nuevo.
- Si algún negocio la exige, el middleware de E frena ahí a la persona.

«Cambiar de teléfono» es quitar la verificación y volver a activarla.

### G. Reinicio por soporte (perdió el teléfono y la clave)

**Comando:** `php artisan comandia:users:reset-two-factor {email} --reason="…"`. El motivo es obligatorio y no hay interfaz.

**Qué hace:**
- Lo mismo que quitar la verificación.
- Además:
  - revoca **todos** los tokens de la app con `AppSessions::revokeAllOf($user, 'support')`, que asienta como «Sistema»;
  - renueva `remember_token`.
- Las sesiones web no se pueden cerrar sin la contraseña en claro, y además nacieron con el factor. El aviso le pide a la persona cambiar su contraseña si no reconoce el reinicio, y eso cierra todo.
- Asienta `TWO_FACTOR_RESET` como «Sistema» en cada negocio (`AccountAudit::inEveryBusinessOfAsSystem`, nuevo) con `after: {reason}`.
- Avisa al titular. Ese aviso es lo que le permite detectar un reinicio que no pidió.

**Procedimiento documentado:** la solicitud llega desde el correo de la cuenta, y la confirma el propietario de uno de sus negocios.

**Quién no puede reiniciarla:** el administrador de un negocio. La cuenta y su protección valen también en otros negocios, y por la regla de D372 no son suyas.

### H. Plataforma (fase 3)

- **Columnas nuevas en `platform_admins`:** `two_factor_secret`, `two_factor_confirmed_at` y `two_factor_last_used_step`. No lleva clave de recuperación: la recuperación es la consola.
- **Alta y reinicio:** `comandia:platform-admin:two-factor {email} {--reset}`.
  - Imprime la clave y la URI `otpauth://`.
  - Pide un código para confirmar.
- **`/plataforma/acceso`:**
  - Pide siempre correo, contraseña y código. Como la verificación es obligatoria, mostrar el campo no revela nada.
  - Usa la misma `SecondFactor`, con la llave de límite propia `two-factor:platform:{id}`.
  - Retira «Mantener la sesión abierta».
- **Con la contraseña correcta y sin verificación configurada:** «Esta cuenta aún no tiene verificación en dos pasos. Configúrala desde la consola del servidor.».
- **Bitácora:** la plataforma no tiene bitácora propia. El acceso y los fallos de código van a un canal de log dedicado mientras no exista.

### I. Lo que no cambia, a propósito

- **Restablecer la contraseña** no toca la verificación y no inicia sesión. Si la quitara, el correo bastaría para saltarse los dos factores.
- **Cambiar la contraseña** y **«Cerrar mis otras sesiones»** siguen pidiendo sólo la contraseña.
- **Cambiar de negocio** no vuelve a pedir el código.
- **PIN, kiosco, impresión y tienda** no cambian.

## Esquema

### `users` (plataforma, sin `tenant_id`)

Migración nueva: `app/Modules/Identity/database/migrations/2026_10_…_rework_two_factor_columns_on_users_table.php`.

| Cambio | Detalle | Por qué |
|---|---|---|
| `two_factor_recovery_codes` | **Se elimina** | Muerta y siempre NULL: sólo la mencionan su migración y el modelo. Su nombre en plural invita a guardar una lista en JSON |
| `two_factor_recovery_hash` | Nueva, `CHAR(64)` ascii/ascii_bin, NULL, después de `two_factor_secret` | El SHA-256 de la clave de recuperación. Es un hash, así que no lleva cast `encrypted`. Precedente: `membership_invitations.token_hash` |
| `two_factor_last_used_step` | Nueva, `BIGINT UNSIGNED` NULL | El paso de tiempo (unix/30) del último código aceptado. Sólo se acepta un paso **posterior**, con un UPDATE condicionado y atómico. Sobrevive a un vaciado de la caché |
| `two_factor_secret`, `two_factor_confirmed_at` | Sin cambios | Ya existen. El secreto conserva el cast `encrypted` (§10.4) y guarda tanto el pendiente como el activo |
| CHECK `chk_users_two_factor_complete` | `two_factor_confirmed_at IS NULL OR (two_factor_secret IS NOT NULL AND two_factor_recovery_hash IS NOT NULL)` | No puede haber una verificación activa sin secreto o sin clave de recuperación. Precedente: el CHECK de `membership_invitations` |
| Índices | **Ninguno** | Toda lectura y escritura es por el PK de una cuenta ya resuelta. Nunca se busca por hash ni por secreto |

**Cambios en el modelo `User`:**
- `$hidden` y los casts cambian `two_factor_recovery_codes` por `two_factor_recovery_hash`, sin cast.
- `two_factor_last_used_step` se castea a `int`.

### `platform_admins` (fase 3)

- **Columnas:** `two_factor_secret` TEXT NULL con cast `encrypted`, `two_factor_confirmed_at` TIMESTAMP NULL y `two_factor_last_used_step` BIGINT UNSIGNED NULL.
- **CHECK** `chk_platform_admins_two_factor_complete`: `two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL`.
- **Índices:** ninguno, por la misma razón que en `users`.

### Sin cambios

- **`personal_access_tokens`, `sessions` y `roles`:** la invariante evita marcar los tokens y las sesiones, y la bandera del rol ya existe.
- **Tablas nuevas:** ninguna. Por eso no se agrega ninguna entidad expuesta (no hacen falta ULID nuevos) y la lista de excepciones de `TenantScopeTest` se queda en cinco entradas.
- **JSON:** no se usa en ningún dato. La bitácora sigue siendo la única que lleva before/after.

### Ajuste

`security.require_two_factor_for_admin_roles` pasa a `enforced: true`, con la descripción nueva del flujo E. El default `false` y el ámbito Tenant se quedan.

### Aislamiento

- **El secreto es de la cuenta.** La obligación se evalúa con el ajuste y el rol activo **del negocio de la petición**.
- **El administrador de A** ve sólo «activa / sin activar» de **su** personal, y no tiene ninguna ruta para tocarla.
- **Los hechos de la cuenta** se asientan en cada negocio activo de la persona. Cada asiento lo firma su membresía de ese negocio, o «Sistema». Es la misma lectura entre negocios que ya hacen el acceso y `AccountAudit`.

## Endpoints, clases y pantallas

### API `/api/v1`

Todas van bajo `auth:sanctum`, se nombran `me.two-factor.*` y se declaran en la categoría «personal» de `RoutePermissionTest`. Son cuatro URIs nuevas: `api/v1/me/two-factor`, `…/confirm`, `…/recovery-key` y `…/disable`.

| Método y ruta | Form Request | Respuesta |
|---|---|---|
| `GET me/two-factor` | — | `TwoFactorStatusResource {enabled, confirmed_at, pending_setup, required_by_active_role, active_role_name}`. Nunca devuelve el secreto |
| `POST me/two-factor` | `StartTwoFactorRequest` (sólo el límite) | 201 `TwoFactorSetupResource {qr_svg_data_uri, setup_key, issuer}`, `no-store`; 409 si ya está activa |
| `POST me/two-factor/confirm` | `ConfirmTwoFactorRequest {password, code}` | `TwoFactorRecoveryKeyResource {recovery_key}`, `no-store` |
| `POST me/two-factor/recovery-key` | `RegenerateRecoveryKeyRequest {password, code}` | `TwoFactorRecoveryKeyResource`, `no-store` |
| `POST me/two-factor/disable` | `DisableTwoFactorRequest {password, code \| recovery_key}` | 204 |
| `POST auth/token` (existente) | `ApiTokenRequest` + `code`, `recovery_key` | 201 / 409 `step: two_factor \| tenant` / 422 |

- **Desde el kiosco,** estas rutas responden 403 «La terminal compartida no tiene una cuenta propia.», como `closeOtherWeb`.
- **Accesos existentes:** `LoginRequest`, `AcceptInvitationRequest`, `ApiTokenRequest` y, en la fase 3, `PlatformLoginRequest` ganan:
  - `code`: `nullable|string|max:12`;
  - `recovery_key`: `nullable|string|max:40`.

  Los dos se excluyen entre sí (`prohibits`).
- **Rutas web:** no hay ninguna nueva.

### Clases (módulo Identity, salvo donde se indica)

**Nuevas:**

- **`Application/Login/VerifyCredentials`:**
  - Usa `guard->validate()` con tiempo igualado, `getLastAttempted()` y vuelve a cifrar la contraseña si hace falta.
  - Lo usan el acceso web, la app y la invitación, y en la fase 3 la plataforma.
  - Resuelve el hallazgo 2.
- **`Application/TwoFactor/SecondFactor`:**
  - `verify(User, ?code, ?recoveryKey, SecondFactorChannel): VerifiedFactor`
  - `spend(VerifiedFactor): void`
  - Lanza `SecondFactorRequired`, `InvalidSecondFactor` o `SecondFactorLocked`.
  - Lleva el límite por cuenta, el asiento de los fallos y el aviso de bloqueo.
  - `spend` hace un UPDATE condicionado: `WHERE two_factor_last_used_step IS NULL OR two_factor_last_used_step < ?`. Si no afecta ninguna fila, el código es inválido. Eso cubre la repetición y dos peticiones simultáneas con el mismo código.
- **`Application/TwoFactor/TwoFactorEnrollment`:**
  - `start`
  - `confirm(User, password, code, ?PersonalAccessToken $keep)`
  - `regenerateRecoveryKey`
  - `disable`
  - `resetBySupport(User, reason)`
- **`Application/TwoFactor/RecoveryKey`:** genera, normaliza y calcula el hash.
- **`Application/TwoFactor/TwoFactorRequirement`:** `applies(RequestContext)` y `blocks(RequestContext)`. Lo usan el middleware y el recurso de estado.
- **`Infrastructure/TwoFactor/TotpAuthenticator`:**
  - Envuelve `pragmarx/google2fa` y `bacon/bacon-qr-code`.
  - `generateSecret`, `provisioningUri` (`otpauth://totp/Comandia:{email}?issuer=Comandia…`), `qrSvgDataUri` y `matchingStep(secret, code, CarbonImmutable $now): ?int` con ventana de ±1.
  - Recibe la hora como parámetro para poder probarse con un reloj fijo.
- **`Http/Middleware/EnsureTwoFactorEnrollment`** y **`Domain/Exceptions/TwoFactorEnrollmentRequired`**.
- **`Http/Controllers/MyTwoFactorController`.**
- **`Mail/AccountSecurityNoticeMail`,** con el enum `SecurityNotice`.
- **`Console/ResetTwoFactorCommand`.**
- **En el kernel:** la interfaz `Shared\Http\ProvidesProblemType`.

**Extensiones de lo que ya existe:**

- **`AccountAudit`:** el método `inEveryBusinessOfAsSystem`.
- **`AppSessions`:** dos motivos nuevos en `$how`:
  - `'two_factor'`, que asienta como la persona;
  - `'support'`, que asienta como «Sistema», igual que `'idle'`.
- **`ApiProblem`:** reconoce la interfaz `ProvidesProblemType`.
- **`bootstrap/app.php`:**
  - registra el middleware y su prioridad;
  - agrega `code` y `recovery_key` a `dontFlash`.
- **`#[\SensitiveParameter]`** en todo parámetro que reciba un código, una contraseña o un secreto.
- **El guardia `web`:** el recordatorio pasa a 30 días con `setRememberDuration(43200)` (decisión 8).

### Pantallas Vue

- **`Admin/Account/Index.vue`:** tarjeta «Verificación en dos pasos» con:
  - el estado;
  - «Activar», que muestra el QR con `<img>`, la clave manual, el campo del código y la contraseña;
  - la clave de recuperación mostrada una sola vez;
  - «Generar clave nueva»;
  - «Quitar»;
  - el aviso cuando el rol activo la exige.
- **`Auth/Login.vue` y `Auth/AcceptInvitation.vue`:** el paso del código y «Usar mi clave de recuperación».
- **`Admin/Roles/Index.vue`:** la casilla.
- **`Admin/Staff`:** la etiqueta «Verificación: activa / sin activar» y el filtro.
- **Panel de configuración:** el ajuste aparece solo, porque el panel se arma desde el catálogo.
- **`resources/js/api/client.js`:** el manejo del 403 `two_factor_enrollment_required`.
- **`Platform/Login.vue`** (fase 3): el campo del código, sin la casilla de recordatorio.

## Permisos y bitácora

### Permisos

**No hay permisos nuevos.**

| Acción | Permiso |
|---|---|
| Ver, activar, generar clave nueva y quitar la mía | Sólo estar autenticado: categoría «personal», como `me/password` |
| Encender la obligación en el negocio | `configuration.tenant.update` |
| Marcar un rol como «exige verificación» | `identity.roles.update`. Propietario queda fijo |
| Ver si alguien de mi personal la tiene activa, y filtrar | `identity.users.view` |
| Reiniciar la de otra persona | **Ningún permiso dentro del negocio.** Sólo soporte, desde la consola |

### Bitácora

Todas las acciones van en `AuditAction` con su etiqueta en español. Ese archivo tiene cambios ajenos sin commit: hay que fusionar con cuidado.

| Acción | Dónde | Actor | `after` |
|---|---|---|---|
| `TWO_FACTOR_ENABLED` (existe). Etiqueta nueva: «Activó la verificación en dos pasos» | Cada negocio activo de la persona | La persona, con su membresía de ese negocio | — |
| `TWO_FACTOR_DISABLED` `auth.two_factor_disabled` «Quitó la verificación en dos pasos» | Igual | La persona | — |
| `TWO_FACTOR_RECOVERY_USED` `auth.two_factor_recovery_used` «Entró con su clave de recuperación» | Igual | La persona | `{channel}` |
| `TWO_FACTOR_RECOVERY_REGENERATED` `auth.two_factor_recovery_regenerated` «Generó una clave de recuperación nueva» | Igual | La persona | — |
| `TWO_FACTOR_FAILED` `auth.two_factor_failed` «Código de verificación incorrecto» | Igual. La contraseña fue correcta, así que se sabe quién es, y es la señal de que se filtró | La persona, como en `LOGIN_FAILED` | `{channel: web\|app\|invitation\|account}` |
| `TWO_FACTOR_RESET` `auth.two_factor_reset` «Soporte reinició su verificación en dos pasos» | Igual | Sistema | `{reason}` |
| `APP_SESSION_REVOKED` | El negocio de cada token | La persona (`how: two_factor`) o Sistema (`how: support`) | `{how}` |
| `LOGIN` | El negocio del token, al emitirlo | La persona | `{channel: "app", device}` |
| `ROLE_CREATED` / `ROLE_UPDATED` (corregidas) | El negocio del rol | Quien edita | Incluyen `requires_two_factor` |

- **Ningún asiento** lleva el secreto, la clave de recuperación ni el código tecleado. Una prueba lo verifica.
- **Eventos de dominio:** ninguno. Nada de esto produce efectos en otros módulos.

### Correos y colas

**`AccountSecurityNoticeMail`:**
- Es `ShouldQueue`, va en la cola `default` y se manda con `Mail::to()->queue()`.
- Sale **de la plataforma**, igual que `ResetPasswordMail`, con el HTML en línea en español.
- Dice:
  - qué pasó;
  - cuándo, en la zona horaria por omisión de la plataforma, dicha explícitamente;
  - por qué canal.
- Cierra con «Si no fuiste tú, cambia tu contraseña», con enlaces a `app.url/olvide-contrasena` y `app.url/admin/mi-cuenta`. Los enlaces nunca se arman con el `Host` de la petición y **nunca llevan tokens** (D373).

| `SecurityNotice` | Cuándo |
|---|---|
| `Enabled` | Al activar |
| `Disabled` | Al quitar |
| `RecoveryUsed` | Al entrar con la clave de recuperación |
| `RecoveryRegenerated` | Al generar una clave nueva |
| `ResetBySupport` | Cuando soporte la reinicia |
| `LockedOut` | Al quinto código fallido. Una vez por ventana de bloqueo, no por cada fallo |

## Seguridad

### Límites

| Qué | Límite |
|---|---|
| Contraseña (acceso web y app) | Los actuales: 5 por minuto por correo e IP |
| Aceptar una invitación | El actual: 5 por minuto por IP |
| Segundo factor (web, app, invitación y Mi cuenta) | **5 fallos cada 15 minutos por cuenta** (`two-factor:user:{id}`), **sin IP**, porque quien tiene la contraseña puede rotar IPs. Los fallos con la clave de recuperación también cuentan. Se revisa **después** de validar la contraseña |
| Iniciar la activación | 5 por minuto por cuenta |
| Confirmar, generar clave nueva y quitar | La contraseña, 5 por minuto por cuenta (patrón de `MyPasswordController`), más el límite del segundo factor para el código vigente. Los fallos al confirmar se comparan contra el secreto pendiente: no cuentan como ataque ni se asientan |

**Riesgo de adivinar el código:** cada intento acierta con probabilidad de 3 entre 10⁶, por la ventana de ±1 paso. Con el límite queda en alrededor de 0.14 % al día, y cada bloqueo manda un aviso: un ataque sostenido se ve desde el primer cuarto de hora.

### Vigencias

- **Código TOTP:** vale en su paso de 30 s y en los vecinos, unos 90 s.
- **Un código emite una sola credencial.** Entrar en dos dispositivos dentro del mismo medio minuto obliga a esperar el siguiente código.
- **Clave de recuperación:** vale hasta que se usa o se reemplaza.
- **Secreto pendiente:** no vence, porque no protege nada. Lo reemplaza el siguiente «Activar».
- **«Mantener la sesión abierta»:** 30 días (decisión 8).

### Mensajes que no enumeran

- **Antes de validar la contraseña,** la respuesta y el tiempo de respuesta son iguales para un correo que no existe, una contraseña mala, una cuenta con verificación y una sin ella.
- **Los mensajes del código** (lo pide, no es válido, demasiados intentos) sólo aparecen después de una contraseña correcta.
- **Un código inválido, vencido o repetido** reciben el mismo texto.
- **Riesgo residual que se acepta:** quien tiene la contraseña sabe que es correcta cuando se le pide el código. Es inherente a cualquier 2FA.

### Secretos

- El secreto se guarda cifrado con la llave de la aplicación (cast `encrypted`).
- Sólo `POST me/two-factor` lo devuelve, y con `no-store`. `GET` nunca lo devuelve.
- No aparece en la bitácora, en el flash de errores (`dontFlash`) ni en las trazas (`#[\SensitiveParameter]`).
- La clave de recuperación sólo se guarda como hash.

### Cierre de sesiones

**Activar cierra todo lo demás.** Si no lo hiciera, una sesión robada sobreviviría justo al acto que pretendía echarla. Quitar la verificación y generar una clave nueva piden contraseña y código, y no abren ningún acceso.

### Bloqueo como forma de negar el servicio

- Sólo lo puede provocar quien ya tiene la contraseña.
- Dura 15 minutos.
- El aviso empuja a la titular a cambiar su contraseña.

### Reloj

El servidor debe sincronizarse por NTP. Se documenta en `docs/ENTORNO_LOCAL.md` y en la guía de despliegue.

### Phishing en tiempo real

TOTP no lo detiene. **Riesgo residual que se acepta,** hasta WebAuthn.

## App Flutter (`C:/Dev/comandia-app`)

El paso del código va en la **fase 1**, junto con el backend: en cuanto alguien active la verificación en el navegador, sin esto no podría entrar a la app.

- **`lib/features/auth/auth.dart`:**
  - `AuthRepository.login({…, code, recoveryKey})`.
  - El 409 se distingue por `step`:
    - `two_factor` lanza la nueva `NeedSecondFactor`;
    - `tenant`, o la presencia de `memberships`, lanza `NeedTenantSelection` como hoy, y así sigue funcionando con un servidor anterior.
  - El 422 en `code` lanza `InvalidSecondFactor`, con el mensaje del servidor.
- **`lib/features/auth/login_screen.dart`:**
  - Un paso de 6 dígitos con teclado numérico y `AutofillHints.oneTimeCode`.
  - «Usar mi clave de recuperación» cambia a teclado de texto.
  - Correo, contraseña y código viven **sólo en memoria** durante los pasos, nunca en `flutter_secure_storage`. Se reenvían con `tenant_ulid` si hace falta.
  - Si el código venció mientras se elegía negocio, se vuelve a pedir.
- **`lib/core/api_client.dart`,** ante un 403 con `type: two_factor_enrollment_required` (fase 2), abre una pantalla de bloqueo con:
  - el `detail`;
  - «Abrir Mi cuenta en el navegador», hacia `app.url/admin/mi-cuenta`;
  - «Cambiar de rol», si la persona tiene otro, vía `X-Role`;
  - «Salir», con `DELETE auth/token`.

  Tras activar la verificación en el navegador, el token de la app recibe 401 y ya regresa al acceso.
- **Activar la verificación desde la app:** no (decisión 9).
- **Kiosco:** sin cambios.
- **Versiones viejas:** el 409 `two_factor` lleva `errors.code` con «actualiza Comandia», que es lo que ya muestran. Conviene publicar la versión nueva junto con la fase 1.
- **Pruebas:**
  - en `test/auth_test.dart`: el 409 `two_factor`, el código seguido del negocio, el código inválido y la compatibilidad con un 409 sin `step`;
  - un archivo nuevo, `test/verificacion_dos_pasos_test.dart`, para la pantalla de bloqueo del 403.

## Pruebas (Definition of Done)

### Unitarias

- **`TotpAuthenticator`,** con los vectores SHA-1 del RFC 6238 y un reloj fijo:
  - la ventana de ±1 paso se acepta y la de ±2 se rechaza;
  - `matchingStep` devuelve el paso correcto.
- **`RecoveryKey`:**
  - formato y entropía;
  - normalización de mayúsculas, guiones, espacios y las equivalencias de Crockford;
  - sólo se guarda el hash.
- **`TwoFactorRequirement`:** la matriz completa de ajuste × bandera del rol × cuenta con o sin verificación × principal `User` o kiosco.

### Acceso web (`AuthenticationFlowTest` + `TwoFactorTest`)

- **Mensajes que no enumeran:** con verificación y contraseña mala (con código o sin él), el mensaje es idéntico al de un correo que no existe.
- **Sin código:** 422 en `code` **y `assertGuest`**. Sólo pasa con la contraseña correcta.
- **Código malo:** `assertGuest`, `TWO_FACTOR_FAILED` en cada negocio de la persona, y nunca en uno donde no trabaja.
- **Código bueno:** entra y asienta `LOGIN`.
- **Repetición:** **el mismo código** no abre una segunda sesión.
- **Bloqueo:** el sexto intento se rechaza, el aviso se encola una sola vez y la ventana se respeta.
- **Clave de recuperación:**
  - entra y desactiva la verificación;
  - usarla otra vez se rechaza;
  - asienta y avisa.
- **Recordatorio:** la cookie sólo se pone después del código y dura 30 días.
- **Varios negocios:** el selector aparece sólo después del código.
- **Restablecer la contraseña:** no toca la verificación, y entrar después sigue pidiendo el código.

### Invitación (`MembershipInvitationTest`)

- Cuenta existente con verificación, contraseña buena y sin código: **no** se acepta y la membresía sigue invitada.
- Código malo: el asiento del fallo **sobrevive** al rollback.
- Con código: se acepta.
- Modo `signed_in`: no pide el código.

### App (`ApiTokenTest`)

- El 409 `two_factor` sólo llega con la contraseña correcta, y el 409 `tenant` sólo con un código válido.
- El 409 `tenant` conserva `memberships`, agrega `step` y no gasta el código. Emitir el token sí lo gasta.
- Un segundo token con el mismo código se rechaza.
- Emitir asienta `LOGIN` con `channel: app`.
- Un correo que no existe tarda lo mismo que una contraseña mala: la prueba verifica que se use `VerifyCredentials`.

### Mi cuenta

- Cada endpoint de `me/two-factor/*`, con sus caminos de error.
- `no-store` en la activación y en la clave de recuperación.
- Ningún recurso devuelve el secreto ni el hash.
- Iniciar con la verificación ya activa responde 409.
- **Confirmar:**
  - otro token de la persona en el negocio B responde 401;
  - otra sesión web vuelve al acceso, tanto en Inertia como en `/api`;
  - una cookie de recordatorio vieja se rechaza;
  - la sesión actual sigue;
  - la clave sale una sola vez;
  - `TWO_FACTOR_ENABLED` aparece en A y en B, cada uno firmado por su membresía, y nunca en C.
- El operador del kiosco recibe 403.

### Obligación

- **Con el ajuste apagado,** un Gerente sin verificación opera igual. Es la regresión que protege los ~1,491 usos actuales de `actingAsSpa`, `Sanctum::actingAs` y `withToken`.
- **Con el ajuste encendido:**
  - la API responde 403 con el `type` y la web redirige a Mi cuenta;
  - todas las rutas de escape responden, y Mi cuenta no cae en un bucle;
  - cambiar con `X-Role` a Cajero deja pasar;
  - un Gerente con la verificación activa pasa;
  - el orden del middleware queda fijado.
- **El operador del kiosco con rol Gerente** queda fuera de la obligación. La prueba **documenta** la exclusión.
- **Roles:** `requires_two_factor` en el before/after de `ROLE_CREATED` y `ROLE_UPDATED`; Propietario no se puede editar.
- **Catálogo:** el ajuste se ofrece en el panel.

### Aislamiento por negocio

- La misma persona es Gerente en A, con el ajuste encendido, y en B, con el ajuste apagado:
  - en A la frenan;
  - en B opera libre con la misma sesión tras cambiar de negocio, y su token de B funciona.
- El filtro `two_factor` de A no cuenta membresías de B.
- El administrador de A ve el estado booleano y no tiene ninguna ruta para reiniciar ni quitar la verificación de otra persona.

### Soporte

El comando:
- limpia la verificación;
- revoca los tokens;
- renueva `remember_token`;
- asienta como «Sistema» en cada negocio, con el motivo;
- avisa.

Sin `--reason`, se niega.

### Candados

- **Nuevo `tests/Architecture/SecondFactorGateTest.php`:**
  - Sólo una lista blanca de clases de `app/` puede llamar a:
    - `Auth::login`, `->login(`;
    - `Auth::attempt` / `guard(...)->attempt(`;
    - `loginUsingId`;
    - `IssueApiToken::issue`.
  - Cada una de esas clases usa `SecondFactor`.
  - `CustomerAuthController` (guardia `customer`) es una excepción declarada.
- **También deben quedar en verde:**
  - `RoutePermissionTest`, con las cuatro URIs nuevas;
  - `EveryEndpointIsExercisedTest`;
  - `AuthorizationDisciplineTest`;
  - `TenantScopeTest`, sin cambios y con sus cinco entradas;
  - `KernelTenantIsolationTest`;
  - `ImmutableTablesTest`.
- **Una prueba recorre la bitácora** después de todos los flujos y verifica que ningún asiento lleve el secreto, la clave ni el código.

### Romper a propósito

Cada una de estas pruebas tiene que fallar al quitar lo que protege:

- volver a `Auth::attempt`;
- quitar el UPDATE condicionado contra la repetición;
- no cerrar sesiones al confirmar;
- quitar `me.*` de las rutas de escape;
- quitar la guarda del kiosco;
- sacar `TWO_FACTOR_FAILED` de antes de la transacción de la invitación;
- abrir una vía de acceso nueva sin `SecondFactor`.

### Ayudantes

- `UserFactory::withTwoFactor()`, con un secreto fijo.
- `tests/Support/Totp::codeFor(User, ?CarbonImmutable)`.
- Como el ajuste nace apagado, `actingAsSpa` y los demás ayudantes no cambian.

### Navegador

- El flujo completo con una app de autenticación real: activar, salir, entrar con código, entrar con la clave y la obligación.
- La suite completa, en paralelo y en serie, antes de cada commit.

## Fases de implementación

Cada fase se prueba y se entrega por separado, con su decisión en `REGISTRO_DECISIONES.md`, que hoy llega hasta D382 (D381 y D382 son la recuperación de contraseña).

### 1. La verificación de la cuenta (D383)

**Dependencias y datos:**
- Librerías con versión fijada.
- Migración de `users`.

**Backend:**
- `VerifyCredentials`, que resuelve el hallazgo 2.
- `SecondFactor`, `TwoFactorEnrollment`, `RecoveryKey` y `TotpAuthenticator`.
- La tarjeta en Mi cuenta.
- El paso del código en el acceso web, la invitación y `auth/token`.
- El recordatorio a 30 días.
- Avisos, bitácora (incluido el `LOGIN` de la app, hallazgo 3) y el comando de soporte.
- `SecondFactorGateTest`.

**App Flutter:** el paso del código.

**Lo que todavía no existe:** la obligación. El ajuste sigue oculto.

### Requisito de la fase 2: el kiosco cumple ADR-012 regla 1 (D384)

- `SharedTerminalPrincipal` sólo pasa una lista blanca de rutas del POS, por nombre de ruta, sea cual sea su rol. La lista sale de lo que realmente llaman la pantalla del POS y la app en modo kiosco.
- La prueba de la regla 1 pasa a ser la que pide la ADR: un operador **con rol de Gerente** contra `/api/v1/memberships` recibe 403.
- No necesita ADR nueva, porque cumple una vigente.

### 2. La obligación (D385)

- El ajuste con `enforced: true`.
- `EnsureTwoFactorEnrollment`, `ProvidesProblemType` y el brazo en `ApiProblem`.
- La casilla y la auditoría de Roles (hallazgo 4).
- El estado y el filtro en Personal.
- El 403 en la SPA y la pantalla de bloqueo en Flutter.

### 3. Plataforma (D386, si se aprueba la decisión 13)

- Las columnas en `platform_admins`.
- El comando `comandia:platform-admin:two-factor`.
- El código en `/plataforma/acceso`, sin recordatorio.
- El canal de log del acceso.

### Documentación

- **Este archivo,** como `docs/iteraciones/DISENO-verificacion-en-dos-pasos.md`, con su estado al aprobarse.
- **En `REGISTRO_DECISIONES.md`:**
  - D383 a D386;
  - cerrar la fila «2FA TOTP»;
  - anotar como pendiente propio el aviso por correo al cambiar la contraseña.
- **Una nota en `docs/iteraciones/ITERACION_1_DISENO.md`** (l.~205): `two_factor_recovery_codes` se reemplaza por `two_factor_recovery_hash` y `two_factor_last_used_step`.
- **El `.module.md` de Identity,** con:
  - lo que queda fuera (PIN, kiosco, autorización por PIN, impresión, tienda);
  - las acciones de bitácora nuevas;
  - que no se emiten eventos de dominio.
- **`docs/ENTORNO_LOCAL.md`:** la nota de NTP.

## Decisiones para el dueño

1. **¿Dónde vive el segundo factor?**
   - **(a) Recomendada:** en la cuenta. Quien lo activa da el código en **todo** acceso nuevo: navegador, invitación y app. La obligación consiste en «tenerla activa», y nada se marca por sesión ni por token. Costo: quien la activa da el código también en un negocio que no la exige.
   - (b) Marcar cada sesión y cada token como «verificado» (columna en `personal_access_tokens` y llave de sesión) y pedir el código sólo a quien opera con un rol que la exige. Es más esquema, más casos y un estado más que robar.

2. **¿Cómo se pide el código?**
   - **(a) Recomendada:** **en la misma petición** que la contraseña, sin estado en el servidor. El cliente reintenta con `code`, como hoy reintenta con `tenant_ulid`. Es una sola forma para web, invitación, app y plataforma; no hay rutas nuevas ni un estado «medio autenticado» que se pueda robar. Costo: la contraseña sigue en la memoria del navegador o de la app durante el paso.
   - (b) Un desafío pendiente en sesión o caché, con un boleto opaco, como Fortify. Son una ruta y un estado nuevos, y la app seguiría necesitando su propio camino.

3. **¿Cómo se recupera la propia persona?**
   - **(a) Recomendada:** **una sola clave** de unos 100 bits, guardada como hash en `users`. Al usarla se gasta y **desactiva** la verificación, y hay que volver a activarla. Se puede generar una nueva con contraseña y código. No agrega tablas y no cambia `TenantScopeTest`.
   - (b) Diez códigos de un solo uso en una tabla nueva de plataforma, `user_recovery_codes`, uno por fila con hash. Es lo más común en la industria y usar uno no desactiva nada, pero es una tabla nueva y una sexta excepción en `TenantScopeTest`.
   - (c) Una lista cifrada en la columna actual, como hace Fortify. Choca con «sin JSON» y obliga a leer, modificar y reescribir bajo lock. No la recomiendo.

4. **¿Qué pasa si perdió el teléfono y la clave?**
   - **(a) Recomendada:** **sólo soporte, por consola**, con un motivo obligatorio. La solicitud llega desde el correo de la cuenta, la confirma el propietario de uno de sus negocios, se asienta como Sistema en cada negocio y se avisa a la titular.
   - (b) Un botón en la consola de plataforma. Es más superficie y más interfaz que proteger.
   - (c) Que la reinicie el propietario del negocio. Viola D372: la cuenta y su protección valen también en otros negocios.

5. **¿Cómo se decide a quién se le exige?**
   - **(a) Recomendada:** con el **ajuste del negocio** más la **bandera del rol activo**, revisado **en cada petición**. Es coherente con D9: quien tiene «Gerente» y «Cajero» puede seguir trabajando como Cajero sin la verificación.
   - (b) El ajuste más **cualquiera** de los roles de la persona, revisado sólo al entrar. Es más estricto y no admite cambiar de rol para seguir operando.
   - (c) Sólo la bandera, sin ajuste. La obligación se encendería al desplegar para todos los Propietarios y Gerentes, porque las plantillas ya traen `true`.

6. **¿Qué cierra sesiones?**
   - **(a) Recomendada:**
     - **activar** cierra los demás navegadores, la app en todos los negocios y los recordatorios;
     - **quitar** y **generar una clave nueva** no cierran nada, porque piden contraseña y código y no abren ningún acceso;
     - **el reinicio por soporte** revoca la app y renueva el recordatorio.
   - (b) Que todo cierre todo. Agrega fricción sin cerrar ninguna amenaza más.
   - (c) Que nada cierre. Rompe la invariante: una sesión robada sobreviviría a la activación.

7. **¿Se puede quitar la verificación mientras un rol la exige?**
   - **(a) Recomendada:** **sí**. La siguiente petición manda a activarla otra vez, y así se cambia de teléfono sin un flujo aparte.
   - (b) Prohibirlo. Obligaría a crear un flujo de «reemplazar» con un segundo secreto pendiente.

8. **¿Cuánto dura «Mantener la sesión abierta»?** Con la verificación, esa cookie se salta los dos factores.
   - **(a) Recomendada:** **30 días** en el guardia `web`. Aplica también a quien no usa la verificación.
   - (b) Dejar los 400 días actuales.
   - (c) Pedir el código al volver por la cookie. Equivale a quitar el recordatorio para quien tiene la verificación.

9. **¿Dónde se activa?**
   - **(a) Recomendada:** **sólo en el navegador**. La app sabe pedir el código y explica la obligación. Hoy la app no tiene pantalla de cuenta, y activar se hace una sola vez.
   - (b) También en la app, con `otpauth://` y `url_launcher`. Obliga a crear la pantalla de cuenta en Flutter.

10. **¿El administrador del negocio ve el estado?**
    - **(a) Recomendada:** **sólo «activa / sin activar»**, en la ficha y como filtro de Personal, con `identity.users.view`. Quien va a encender la obligación necesita saber a quién va a frenar. No revela nada de otros negocios y no lleva fecha.
    - (b) No mostrar nada, porque es un dato de la cuenta.

11. **¿Qué avisos se mandan por correo?**
    - **(a) Recomendada:** los seis de la tabla: activó, quitó, usó la clave, generó una clave nueva, soporte reinició y bloqueo tras cinco fallos (uno por bloqueo).
    - (b) Además, uno por cada código fallido, con un máximo de uno por hora. Es más ruido: un error de dedo de la dueña dispararía «alguien tiene tu contraseña».
    - (c) Ninguno. La titular no se enteraría de un reinicio ni de un ataque.

12. **¿Qué se hace con el kiosco frente a la obligación?** Hallazgo 1.
    - **(a) Recomendada:** **hacer cumplir ADR-012 regla 1**: el operador del kiosco sólo pasa rutas del POS, sea cual sea su rol. Va como decisión propia (D384), sin ADR nueva porque cumple una vigente, y es **requisito previo** de encender la obligación: sin esto, un PIN de cuatro dígitos daría administración sin segundo factor.
    - (b) Negar la identificación por PIN a los roles que exigen la verificación cuando el negocio la tiene encendida. **Enmienda ADR-012 y exige una ADR nueva**, y deja a los gerentes sin terminal compartida.
    - (c) Aceptar el hueco.

13. **¿Y los administradores de plataforma?**
    - **(a) Recomendada:** **verificación obligatoria** en la fase 3. Alta y reinicio por consola, sin clave de recuperación (la consola es la recuperación) y sin «mantener la sesión abierta». Son las cuentas que crean y suspenden negocios, y el servicio es el mismo.
    - (b) Opcional, con una pantalla propia en la consola de plataforma.
    - (c) Dejarla fuera, como pendiente.

14. **¿Se resuelven aquí los hallazgos vecinos?** Son el tiempo de respuesta de `auth/token` (2), el `LOGIN` de la app (3) y la bandera en Roles y en su auditoría (4).
    - **(a) Recomendada:** **sí**, en las fases 1 y 2. Los tres tocan exactamente el código que este diseño reescribe, y sin el 4 la obligación no se puede configurar desde la interfaz (D360).
    - (b) Abrirlos aparte. Habría que volver a tocar los mismos controladores, y entretanto la obligación quedaría sin pantalla.

15. **¿Qué librería TOTP?**
    - **(a) Recomendada:** `pragmarx/google2fa` (RFC 6238, con verificación de pasos posteriores) más `bacon/bacon-qr-code` (QR en SVG desde el servidor), con versiones fijadas al instalar.
    - (b) `laravel/fortify`. Trae sus propias rutas y flujos, que chocan con `LoginController`, `ApiTokenController`, la invitación y los candados de rutas.
    - (c) TOTP escrito a mano. Es criptografía casera, sin necesidad.
