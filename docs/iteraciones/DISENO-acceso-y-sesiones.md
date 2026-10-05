# Diseño — Acceso: contraseñas, invitaciones y sesiones

> Estado: **aprobado** el 2026-09-26 con las siete recomendaciones, e **implementado** en tres fases: D372 (salir de
> verdad y dispositivos), D373 (contraseñas) y D374 (invitaciones). Cierra dos pendientes de D360: «cambiar y recuperar
> la contraseña, y dar acceso a una persona dada de alta sin correo» y «cerrar sesión en la app no revoca el token; no
> hay lista de dispositivos».
>
> Diferencias con lo propuesto, decididas al implementar: el nombre en la barra superior lleva a «Mi cuenta» (en vez de
> un botón más, que no cabía en un teléfono); aceptar una invitación y reactivar a alguien respetan el límite de
> personas del plan; y cambiar la contraseña conserva la sesión sin regenerar su identificador: basta con que la sesión
> guarde el cifrado nuevo al terminar la petición, que es lo que ya hace `AuthenticateSession`.

## Lo que hay hoy

- **La cuenta es de la plataforma, no del negocio.** `users` lleva el correo, único en toda la plataforma, y **una sola
  contraseña** para todos los negocios donde la persona trabaja (puede estar en varios). Lo que es del negocio es la
  membresía (`tenant_memberships`).
- **No hay forma de cambiar ni de recuperar la contraseña.** La tabla `password_reset_tokens` y el broker de Laravel
  (60 minutos de vigencia) existen sin uso. No hay página de «mi cuenta».
- **El alta con acceso pide correo y una contraseña que teclea el administrador.** La persona nace «invitada» y el
  administrador la «activa». Si el correo ya existe en otro negocio, se reusa esa cuenta: su contraseña no se toca, lo
  tecleado se ignora sin avisar, y la persona queda sumada a este negocio sin haberlo aceptado.
- **El alta sin correo** crea sólo la membresía (nómina, PIN). Después no hay forma de darle acceso: `user_id` está
  prohibido al editar.
- **La app** entra con un token de Sanctum por persona y negocio (`personal_access_tokens`, con `tenant_id` y
  `membership_id`), con el nombre fijo «App Comandia» y sin caducidad. **Salir en la app sólo borra el token del
  teléfono**: en el servidor sigue valiendo. No hay endpoint para revocarlo ni lista de dispositivos. Suspender a alguien
  sí borra sus tokens.
- **Sesiones web:** guardia `web` (sesión) más Sanctum por cookie para `/api`. Sanctum ya invalida en `/api` una sesión
  cuya contraseña cambió; las pantallas Inertia no llevan esa comprobación (`auth.session`). En desarrollo las sesiones
  van a MySQL; `.env.example` las manda a Redis en producción, donde no se pueden listar por persona.
- **Correo:** `TenantMailer` envía por el SMTP del negocio y cae al de la plataforma si no hay; envía **síncrono**.
- El ajuste `security.password_min_length` **no lo lee nadie**; el mínimo de 10 está fijo en el alta.
- Los dispositivos de **terminal compartida** (ADR-014) son otra credencial, por terminal y sin persona. Se quedan
  aparte: este diseño no los toca.

## Decisiones que necesito, con mi recomendación

1. **Invitar en vez de teclear la contraseña de otro.** Recomendado: **sí**. Nadie debería conocer la contraseña de
   otra persona, y una invitación además prueba que el correo es suyo y le pide su consentimiento para entrar a este
   negocio. Se retira el campo de contraseña del alta. Alternativa: conservar ambos caminos.
2. **Qué correo manda qué.** Recomendado: la **recuperación** sale del correo de la **plataforma** (la cuenta es de la
   plataforma y el enlace se pide sin negocio); la **invitación** sale del correo del **negocio** (`TenantMailer`, que
   cae al de la plataforma si el negocio no configuró el suyo), porque es el negocio quien invita.
3. **Cambiar o recuperar la contraseña cierra todo lo demás.** Recomendado: **sí**, las demás sesiones web **y** todas
   las sesiones de la app, en todos sus negocios. Quien cambia su contraseña porque sospecha algo necesita que el otro
   quede fuera; el costo es volver a entrar en el teléfono.
4. **Sesiones web: ¿lista o botón?** Recomendado: **listar sólo los dispositivos de la app** y, para el navegador, un
   botón «Cerrar mis otras sesiones web» sin lista. Con Redis las sesiones no se pueden listar por persona. Alternativa:
   guardar las sesiones en MySQL también en producción y listarlas con navegador, IP y última actividad.
5. **Caducidad de la app por falta de uso.** Recomendado: **60 días sin usarse** y el token se revoca solo (tarea
   diaria). Hoy un teléfono perdido hace un año sigue entrando.
6. **Mínimo de la contraseña.** Recomendado: **regla de plataforma de 10 caracteres** y **retirar el ajuste por
   negocio**: no gobierna nada y no puede gobernar una contraseña que es la misma en varios negocios (como D351).
7. **Enlace de invitación copiable.** Recomendado: **sí**, visible una sola vez al crear la invitación, para mandarlo
   por WhatsApp cuando el correo no llega. Da lo mismo que hoy da la contraseña tecleada, sin que nadie la conozca.

## Flujos

### A. Cambiar mi contraseña

«Mi cuenta» (`/admin/mi-cuenta`, enlace junto a «Salir» en la barra superior): contraseña actual, nueva y
confirmación. El servidor verifica la actual, exige 10 caracteres y que sea distinta, la guarda, **conserva la sesión
actual** (regenerándola) y cierra todo lo demás (decisión 3). Queda en la bitácora de cada negocio donde la persona
tiene acceso activo.

### B. Olvidé mi contraseña

1. En el acceso, «¿Olvidaste tu contraseña?» lleva a `/olvide-contrasena`: se escribe el correo.
2. La respuesta es **siempre la misma** —«Si ese correo tiene cuenta, te enviamos un enlace que vence en 60
   minutos»— y el correo sale **por cola**: ni el texto ni el tiempo de respuesta dicen si la cuenta existe.
3. El enlace (`/restablecer/{token}`) pide la nueva contraseña. Al guardarla: marca el correo como verificado si no lo
   estaba (el enlace llegó a él), cierra todas las sesiones y tokens, gasta el enlace y manda a la pantalla de acceso con
   «Contraseña actualizada: entra con la nueva». **No inicia sesión solo**: un enlace robado no se convierte en una
   sesión abierta.
4. Una cuenta sin ningún negocio activo también puede restablecer (la cuenta es de la plataforma); entrar sigue
   exigiendo una membresía activa, como hoy.

### C. Dar acceso con una invitación

**Quién:** quien tenga `identity.users.create`.

- **Al dar de alta** «con acceso al sistema»: se piden correo y roles, ya no contraseña. La persona nace **invitada** y
  se le manda la invitación.
- **A alguien «sólo nómina»** (sin cuenta): botón **«Dar acceso»** en su fila y en su ficha → correo y roles →
  invitación.
- **A una invitada:** **«Reenviar invitación»** (cancela la vigente y manda otra) y **«Cancelar invitación»**.
- La pantalla dice siempre «Invitación enviada a …» y ofrece **«Copiar enlace»** una sola vez. **No revela** si el
  correo ya tiene cuenta en la plataforma.

**Aceptar** (`/invitacion/{token}`, página pública con el nombre del negocio y los roles):

- **Correo sin cuenta:** nombre (precargado del perfil de empleado, si hay), contraseña y confirmación. Se crea la
  cuenta con el correo verificado, se liga a la membresía, se le asignan los roles de la invitación, la membresía pasa a
  activa y se entra a ese negocio.
- **Correo con cuenta** (trabaja en otro negocio): pide su contraseña de siempre —o sólo «Aceptar» si ya tiene la
  sesión abierta con ese correo— y hace lo mismo sin crear nada. Es el consentimiento que hoy falta.
- **Sesión abierta con otro correo:** pide salir primero. Nunca se liga una invitación a quien no es su destinatario.
- La invitación **vence a los 7 días** y **se usa una vez**. Vencida, usada o cancelada responde que ya no vale y que
  se pida otra.

**«Activar» deja de aplicar a invitadas:** una invitada se activa al aceptar, no porque el administrador lo decida.
Las invitadas que existan hoy (creadas con contraseña tecleada) aparecen como «Invitada — sin enlace» con **«Enviar
invitación»**.

### D. Salir de verdad, y mis dispositivos

- **Salir en la app revoca su token** en el servidor (`DELETE /api/v1/auth/token`). La app lo llama al salir y borra
  lo local aunque la red falle.
- **«Mi cuenta › Dispositivos»:** la lista de las sesiones de la app de la persona —nombre del dispositivo, negocio,
  cuándo entró, último uso—, con «Cerrar» por cada una y «Cerrar todas». Y el botón «Cerrar mis otras sesiones web»,
  que pide la contraseña actual (una sesión robada no puede echar a la dueña).
- **En la ficha de una persona** (administración), la pestaña **«Sesiones en la app»**: sólo los tokens **de este
  negocio**, con «Cerrar» — el teléfono perdido de un mesero. Nunca se ven ni se cierran las sesiones que la persona
  tenga en otro negocio.
- **Nombre del dispositivo:** la app manda marca, modelo y sistema («Android · Moto G32») en vez de «App Comandia».
- **La app ante un 401** (token revocado desde otro lado) vuelve a la pantalla de acceso; hoy sólo el modo kiosco lo
  hace.
- **Caducidad** por falta de uso (decisión 5): una tarea diaria revoca los tokens sin usarse en 60 días.
- **Pantallas web:** se agrega `auth.session` a las rutas web autenticadas, para que cerrar las otras sesiones y cambiar
  la contraseña surtan efecto también en Inertia y no sólo en `/api`.

## Entidades y datos

1. **`membership_invitations`** (nueva, de negocio):
   - `id` BIGINT PK · `ulid` CHAR(26) · `tenant_id` FK NOT NULL (ADR-002) · `membership_id` FK NOT NULL ·
     `email` VARCHAR(150) NOT NULL · `token_hash` CHAR(64) ascii NOT NULL · `invited_by_membership_id` FK NOT NULL ·
     `expires_at` TIMESTAMP NOT NULL · `accepted_at` NULL · `revoked_at` NULL · timestamps.
   - **Índices:** `unique(ulid)`; `unique(token_hash)` — aceptar busca por el token, sin contexto de negocio;
     `(tenant_id, membership_id)` — la invitación vigente de una persona, al reenviar y al pintar su ficha.
   - **CHECK:** `accepted_at` y `revoked_at` no pueden estar llenos a la vez.
   - **Una vigente por membresía:** en la aplicación, bajo lock de la membresía (MySQL no tiene índices parciales).
   - **FKs:** `membership_id` en cascada (si la membresía se borra, su invitación no tiene a quién dar acceso);
     `invited_by_membership_id` restrictiva.
   - Aceptar busca el token **sin contexto de negocio** y fija el contexto con el del negocio dueño, igual que la tienda
     por su slug: es una excepción de alcance justificada que se suma a `AuthorizationDisciplineTest`.
2. **`membership_invitation_roles`** (nueva): `id`, `tenant_id` FK NOT NULL, `invitation_id` FK en cascada,
   `role_id` FK en cascada. `unique(invitation_id, role_id)`, que además resuelve la búsqueda por invitación. Existe
   porque los roles viven en la cuenta (Spatie con equipos = negocio) y una persona sin cuenta no puede tenerlos
   todavía; sin JSON en datos de dominio, la lista va en su tabla.
3. **`personal_access_tokens`:** se agrega `ulid` CHAR(26) `unique`, para revocar uno por la API sin exponer el id
   secuencial. `name` guarda el nombre del dispositivo.
4. **`password_reset_tokens`:** la de Laravel, sin cambios. Es de la plataforma, como `users` y `sessions`: sin
   `tenant_id`, igual que ellas.
5. **Ajuste retirado:** `security.password_min_length` (decisión 6).

## Permisos (sin permisos nuevos)

| Acción | Permiso |
|---|---|
| Cambiar mi contraseña, ver y cerrar mis sesiones, salir en la app | Sólo estar autenticado: es de la persona, como `preferences/theme` (categoría «personal» que `RoutePermissionTest` ya admite) |
| Invitar, reenviar y cancelar una invitación | `identity.users.create` |
| Ver y cerrar las sesiones de la app de otra persona, en este negocio | `identity.users.suspend` (quien puede suspender ya revoca todas) |
| Olvidé, restablecer y aceptar una invitación | Públicas, con límite de intentos (como el acceso) |

## Endpoints

**Web (Inertia):** `GET/POST /olvide-contrasena` · `GET /restablecer/{token}` · `POST /restablecer` ·
`GET/POST /invitacion/{token}` · `GET /admin/mi-cuenta`.

**API `/api/v1`:** `PUT me/password` · `GET me/sessions` · `DELETE me/sessions/{ulid}` · `DELETE me/sessions` (todas
las de la app) · `POST me/sessions/close-other-web` · `DELETE auth/token` · `POST memberships/{m}/invitation` (crear o
reenviar) · `DELETE memberships/{m}/invitation` · `GET memberships/{m}/app-sessions` ·
`DELETE memberships/{m}/app-sessions/{ulid}`. Todas con Form Request a la entrada y Resource a la salida.

## Bitácora

Acciones nuevas: `PASSWORD_CHANGED`, `PASSWORD_RESET`, `INVITATION_SENT`, `INVITATION_REVOKED`,
`INVITATION_ACCEPTED`, `APP_SESSION_REVOKED`, `OTHER_WEB_SESSIONS_CLOSED`.

- Las de invitación y de sesiones de la app, en el negocio al que pertenecen.
- **Cambiar y restablecer la contraseña** se asientan en **cada negocio donde la persona tiene acceso activo**: es un
  cambio en quién puede entrar a cada uno, y cada dueño tiene que poder verlo. Recorre sólo las membresías de esa
  persona —la misma lectura que ya hace el acceso para elegir negocio— y se declara como excepción justificada.

## Seguridad

- Los enlaces (restablecer, invitación) llevan un token aleatorio de 64 caracteres que **sólo se guarda como hash**, se
  usa una vez y vence (60 minutos y 7 días).
- Ninguna respuesta pública dice si un correo tiene cuenta.
- **Límites de intentos:** pedir el enlace, 3 por correo e IP cada 15 minutos (además del minuto del broker);
  restablecer y aceptar, 5 por minuto por IP; cambiar la contraseña, 5 por minuto por persona.
- Cambiar o restablecer cierra todo lo demás (decisión 3). Cerrar las otras sesiones web pide la contraseña actual.
- Un administrador nunca ve ni cierra sesiones de otro negocio; ni siquiera sabe si existen.
- Los correos salen **por cola** (`default`), con la lógica de envío fuera de la petición. `TenantMailer` gana una
  variante encolada que restablece el contexto del negocio en el trabajo.

## Fases propuestas

1. **Salir de verdad y dispositivos:** `DELETE auth/token`, `ulid` en los tokens, «Mi cuenta › Dispositivos», la
   pestaña de la ficha, `auth.session` en web, la caducidad por falta de uso, y en la app: salir contra el servidor,
   nombre del dispositivo y 401 → acceso.
2. **Contraseñas:** cambiar la mía, olvidé y restablecer, y retirar el ajuste de longitud.
3. **Invitaciones:** tablas, correo, aceptación, pantallas de personal y retiro de la contraseña tecleada.

Cada fase se prueba y se entrega por separado.

## Definition of Done

- **Feature por endpoint**, incluidos los caminos de error: token vencido, usado o de otra invitación; correo que no
  existe (misma respuesta, sin correo enviado); invitación a quien ya está en el negocio; aceptar con la sesión de otra
  persona.
- **Aislamiento de negocio:** un administrador de A no ve ni cierra las sesiones que la misma persona tiene en B; una
  invitación de A no liga una membresía de B; la bitácora de B no recibe nada que no sea de B, salvo el cambio de
  contraseña de alguien con acceso en B.
- **Autorización** de cada acción administrativa, y **límites de intentos** en las públicas.
- **Cierre de sesiones de verdad:** tras cambiar la contraseña, un token viejo responde 401 y una sesión web vieja
  vuelve al acceso, en `/api` y en Inertia.
- **Idempotencia:** aceptar dos veces la misma invitación no liga ni activa dos veces; reenviar deja una sola vigente.
- **Candados:** `KernelTenantIsolationTest` con las dos tablas nuevas, `AuthorizationDisciplineTest` con las dos
  excepciones de alcance, `RoutePermissionTest` y `EveryEndpointIsExercisedTest` en verde, cada uno roto a propósito una
  vez para verlo fallar.

## Fuera de alcance

- **2FA TOTP** (pendiente propio, ARQUITECTURA §10.2).
- **Cambiar el correo** de una cuenta.
- **Recuperar la contraseña** de los administradores de plataforma (guardia `platform`) y de los **clientes de la tienda
  en línea** (su propio guardia): el mismo mecanismo con su broker, cuando se decida.
- **Alta del propietario desde la consola de plataforma** con invitación (hoy el superadministrador teclea su
  contraseña): se puede sumar a la fase 3 si se aprueba.
