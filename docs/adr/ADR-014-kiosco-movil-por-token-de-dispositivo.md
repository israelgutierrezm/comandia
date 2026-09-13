# ADR-014 — Terminal compartida en la app: kiosco móvil por token de dispositivo

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-09-13 |
| **Iteración** | Evolución (posterior a la hoja de ruta §14) |
| **Reemplaza a** | — |

> **No reemplaza ninguna ADR.** Es una **adenda a ADR-012**: añade un segundo camino de
> autenticación —por **token**— para que la terminal compartida operada por PIN funcione también en la
> app Flutter, que no usa cookies. El modelo (dispositivo enrolado + operador por PIN + rol activo +
> atribución sin usuario) no cambia; cambia sólo **cómo viaja la identidad**. El flujo web por cookie
> queda intacto.

## Contexto

ADR-012 resolvió la terminal compartida sobre **sesión de Sanctum con estado** (cookie): el canje del
secreto del dispositivo, el PIN del operador y las rutas del POS dependen de `laravel_session` + CSRF +
`SANCTUM_STATEFUL_DOMAINS`. Es lo correcto para el POS web (una SPA de primera parte).

La app Flutter, en cambio, autentica **por token `Bearer`** (dio + almacenamiento seguro), sin cookies
ni CSRF. La nota de fase de ADR-012 anticipó el kiosco Flutter *"reutiliza este backend con un token
Sanctum sobre `terminal_devices`"*, pero ese camino **no estaba construido**: `TerminalDevice` no emitía
token alguno. Sin él, el kiosco móvil no puede consumir la terminal compartida.

## Decisión

Añadir un **camino por token**, paralelo y aditivo al de cookie, con estas piezas:

### 1. Token de dispositivo propio (no Sanctum)

El dispositivo canja el **mismo secreto de enrolamiento** por un **token de dispositivo** opaco, guardado
**hasheado** (`sha256`) en `terminal_devices.token_hash` y mostrado en claro una sola vez —exactamente el
patrón del **agente de impresión** (`AuthenticatePrintAgent`)—. El cliente lo envía en el encabezado
`X-Terminal-Token`.

**Por qué NO un token Sanctum.** Un token Sanctum sobre el dispositivo haría que el gate `auth:sanctum` lo
aceptara **por sí solo**: el dispositivo *en el bloqueo* (sin operador) alcanzaría el POS. Con un token
propio —que Sanctum no resuelve— un dispositivo sin operador **no** pone principal y el gate responde 401,
igual que por cookie. Sólo un operador fresco satisface el gate. Es la misma razón por la que el agente de
impresión no es un usuario Sanctum.

### 2. La capa de operador vive en la fila del dispositivo

Sin cookie no hay sesión donde guardar al operador, así que se persiste en el propio `terminal_devices`:
`operator_membership_id` + `operator_last_activity_at`. **Un operador a la vez por dispositivo**, que es
justo el modelo de la terminal compartida. El PIN lo fija; salir, la inactividad o revocar lo limpian.

Se descartó **encodear al operador en un segundo token** (token de operador con expiración): la
inactividad **deslizante** (que se renueva con cada acción) es incómoda sobre un token de expiración fija,
y llevar la membresía dentro del token obliga a inventar dónde guardarla. La columna es el gemelo exacto
de lo que la cookie guarda en sesión, con expiración deslizante trivial y revocación instantánea.

### 3. Resolución compartida, no duplicada

La parte delicada —inactividad, rol activo, `RequestContext`, principal que satisface el gate— se extrae a
`SharedTerminalResolver`, que opera sobre una `SharedTerminalState` con dos respaldos: la sesión (cookie,
`SharedTerminalSession`) y la fila del dispositivo (token, `TerminalDeviceState`). Los dos middlewares
—`ResolveSharedTerminal` (cookie) y `ResolveSharedTerminalToken` (token)— comparten esa lógica en vez de
llevar dos copias que se desvíen. Ambos corren **antes del gate** `auth:sanctum`.

### 4. Endpoints (gemelos de los de ADR-012)

- `POST /api/v1/shared-terminal/token` — canjea el secreto por un token de dispositivo. Sin `auth`
  (es lo que establece la identidad), como `shared-terminal/session` y `auth/token`.
- `POST /api/v1/shared-terminal/token/operator` — identifica por código + PIN; gateado por `device.token`
  + `throttle:pin`. Reusa `SharedTerminalLogin` (mismo bloqueo de PIN, D54/D55).
- `DELETE /api/v1/shared-terminal/token/operator` — salir; gateado por `device.token`. Idempotente.

### 5. Revocar deja el aparato fuera al instante

Revocar el dispositivo (endpoint admin existente) además **anula el token** y **olvida al operador**: por
cookie o por token, el aparato queda fuera de inmediato.

## Consecuencias

- **Positivas.** El kiosco móvil consume la terminal compartida con el modelo de token que la app ya usa,
  sin cookies ni CSRF ni dominio stateful. El flujo web por cookie no se toca. La lógica de operador queda
  en un solo lugar para los dos caminos. La superficie de daño de un token robado es la misma que la de un
  secreto de dispositivo: operar el POS de esa terminal, nunca administración ni otra sucursal.
- **Costo.** `terminal_devices` gana tres columnas y un middleware más corre en `/api` (inerte sin el
  encabezado). El detector estructural de rutas sin permiso suma dos excepciones declaradas.
- **Deuda declarada.** (a) La app en su **Fase B** pega el secreto de enrolamiento fuera de banda; no hay
  pantalla de *enrolar* dentro de la app todavía. (b) El *screen-pinning* / lock-task de Android (kiosco a
  prueba de salidas) queda fuera de alcance y se anota como evolución.

## Alternativas consideradas

1. **Cliente cookie/SPA en Flutter** (cookie jar + CSRF + `SANCTUM_STATEFUL_DOMAINS`). Reutiliza el
   backend tal cual, sin cambios. Descartada: frágil en móvil (persistir cookie, CSRF, casar el dominio
   stateful) y rompe el modelo de token de la app, mezclando dos mecanismos de autenticación en el mismo
   cliente.
2. **Token Sanctum sobre `terminal_devices`.** Lo que sugería la nota de ADR-012. Descartada por el §1: el
   gate lo aceptaría sin operador.
3. **Token de operador aparte.** Descartada por el §2 (inactividad deslizante + dónde guardar la membresía).
