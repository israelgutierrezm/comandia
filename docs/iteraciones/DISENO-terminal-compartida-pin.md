# Diseño de iteración — Terminal compartida operada por PIN (web POS)

> Implementa **ADR-012**. Estado: **aprobado**. **Fase (a) — backend web POS: implementada** (enrolamiento,
> canje de secreto → sesión de dispositivo, identificación del operador por código+PIN, salir, inactividad,
> gate `auth:sanctum` sin usuario y atribución por membresía; con su suite del DoD). **Fase (b) — frontend
> web: implementada** (enrolamiento desde Terminales con secreto de una sola vez; antesala `/terminal` que
> pega el secreto y presenta la pantalla de bloqueo con `PinKeypad`; `AdminLayout` en modo kiosco con
> operador + Salir + auto-bloqueo; el POS reutilizado tal cual, servido a la sesión de dispositivo por
> `EnsurePosShellAccess`).
> Alcance: **web POS** (estación fija). El kiosco Flutter queda para después reutilizando el mismo backend.

## Decisiones confirmadas (entradas de este diseño)

1. Identificación del operador: **código de empleado + PIN** (teclado en pantalla).
2. Plataforma: **web POS primero**.
3. Fin de la sesión de operación: botón **Salir** + **inactividad 90 s** (ajuste por sucursal).
4. **Enrolamiento** del dispositivo por alguien con permiso (una vez).
5. **Coexiste** con el login de usuario: sólo las terminales marcadas como compartidas usan este modo.

---

## La decisión de arquitectura clave (necesito tu OK)

El web es **Inertia + sesión**, atado a `Auth::user()`. Pero el operador de una terminal compartida
puede ser un **empleado sin cuenta** (D8): tiene membresía + PIN, **no tiene User**. Por lo tanto:

> **El "quién opera" es una MEMBRESÍA resuelta de la sesión del dispositivo, no `Auth::user()`.**

Modelo para el web: **sesión de dispositivo** (cookie, sin usuario, atada a la terminal) + **operador**
(`membership_id` + marca de tiempo) guardado en esa sesión al teclear el PIN. El servicio de contexto
(`ResolveTenantContext`) gana una vía nueva: cuando la petición viene de una sesión de dispositivo, la
membresía activa y el rol activo se resuelven **del operador de la sesión**, no de un usuario. En todo
lo demás, el contexto (tenant, sucursal, rol activo D9) funciona igual.

Esto es lo que hace que un mesero sin cuenta pueda operar, y es la razón de ser del ADR. Si prefieres
atarlo a un User (excluyendo al personal sin cuenta), cámbiame esta premisa antes de que implemente.

---

## Entidades y datos

- **`terminals.is_shared`** — `boolean NOT NULL default false`. Marca la estación compartida. *Sin
  índice:* hay pocas terminales por sucursal y siempre se consultan ya acotadas por sucursal.
- **`terminal_devices`** — un navegador/dispositivo enrolado a una terminal compartida.
  - `id` BIGINT PK · `tenant_id` BIGINT NOT NULL (ADR-002) · `terminal_id` FK NOT NULL ·
    `label` string · `secret_hash` string (el secreto se muestra una vez; se guarda hasheado) ·
    `last_seen_at` nullable · `revoked_at` nullable · timestamps.
  - Índice compuesto **`(tenant_id, terminal_id)`** (justificado: resolver el dispositivo activo de una
    terminal). `unique(tenant_id, terminal_id)` si sólo permitimos un dispositivo por terminal (a
    decidir; propongo permitir varios y revocar por separado → sin unique).
- **Sesión de operación**: **no lleva tabla.** Vive en la sesión del navegador:
  `operator_membership_id`, `operator_since`, `operator_last_activity`. Caduca por inactividad (90 s).

## Ajuste (D20)

- **`pos.shared_terminal_idle_seconds`** — `Int`, default `90`, ámbito **Branch**. Caso de uso: el
  relevo entre meseros no dura igual en un bar lleno que en una fonda tranquila.

## Permisos (catálogo cerrado, seeder versionado)

- Nuevo: **`organization.terminals.enroll`** — enrolar un dispositivo como terminal compartida.
- Operar: los permisos del **rol de la membresía operadora** (Mesero / Mesero con cobro), vía rol
  activo (D9). Una sesión de dispositivo **sin operador** no tiene permisos de POS: sólo alcanza el
  bloqueo y el endpoint de identificación.

## Flujo (web)

1. **Enrolamiento**: un usuario con `organization.terminals.enroll`, ya logueado, abre *"enrolar esta
   terminal como compartida"* → el server crea `terminal_devices`, entrega el **secreto una sola vez**
   (el navegador lo guarda) y pone `terminals.is_shared = true`.
2. **Arranque**: el navegador canjea el secreto → **sesión de dispositivo** (sin usuario). Pantalla de
   **bloqueo**: código de empleado + PIN (reusa el `PinKeypad` que ya existe).
3. **Identificación**: `POST` valida código+PIN reutilizando el núcleo de `PinAuthorizationService`
   (hash del PIN + bloqueo por intentos) contra una membresía de la sucursal del dispositivo → guarda
   el operador en la sesión.
4. **Operación**: el POS (cuentas, comandas, cobro) corre bajo el operador; cada acción refresca
   `operator_last_activity`; el actor que se registra es esa membresía (`created_by_membership_id`,
   `waiter_membership_id`, que ya existen).
5. **Salir / inactividad 90 s** → se limpia el operador → vuelve al bloqueo.

## Endpoints (`/api/v1` + rutas web según corresponda)

- `POST terminals/{terminal}/enroll` (sesión de usuario con permiso) → secreto de dispositivo.
- `POST device-session` (canje secreto → sesión de dispositivo).
- `POST pos/operator` (sesión de dispositivo) → código+PIN → operador en sesión. **Rate-limit por
  dispositivo/IP (D55)** + bloqueo de PIN (D54).
- `DELETE pos/operator` (Salir).
- Los endpoints de POS existentes aceptan la sesión de dispositivo **sólo si hay operador**; el actor
  sale del operador.

## Seguridad

- Sesión de dispositivo: sin usuario, acotada a POS de su tenant/sucursal/terminal; secreto
  **revocable** (`revoked_at`); jamás alcanza administración ni otra sucursal (ADR-002 + guard).
- Operador: idle 90 s, Salir, bloqueo de PIN por intentos (D54). El PIN de baja entropía es aceptable
  porque sólo identifica **localmente** en un dispositivo ya autenticado (ADR-012).
- **ADR-008 intacta**: autorizar una acción sensible sigue siendo acto de un tercero, sin sesión,
  unión de roles, un solo uso. Los dos usos del PIN no se cruzan.
- Guard: una sesión de dispositivo **sin operador** que intente una acción de POS → 401 (re-identifícate).

## Definition of Done (pruebas)

- **Aislamiento de tenant**: sesión de dispositivo de A no ve ni mueve nada de B.
- **Autorización**: dispositivo sin operador → 401 en acciones POS; con operador → sólo permisos de su
  rol; el token/secreto de dispositivo no alcanza rutas de administración → 403.
- **PIN**: código+PIN válido → operador; inválido → error homogéneo + bloqueo tras N; `idle > 90 s` →
  exige re-identificación.
- **Atribución**: comanda/cobro bajo operador registran su membresía (feature test).
- **ADR-008 sigue verde** (su test estructural no se toca).
- **Enrolamiento** exige el permiso; sin él → 403.
- Form Requests para toda entrada; Resources para toda salida; sin lógica de negocio crítica en Vue.

## Frontend (web POS)

- Modo "terminal compartida": si hay sesión de dispositivo → **pantalla de bloqueo** (código + PIN,
  reusa `PinKeypad`) → POS bajo el operador → botón **Salir** + auto-bloqueo por inactividad.

## Fuera de alcance (ADR/iteración futura)

- Kiosco Flutter (reutiliza este backend con un token Sanctum sobre `terminal_devices`).
- Varios operadores simultáneos en la MISMA terminal (una terminal = un operador a la vez).
- Biometría / NFC como identificación.
