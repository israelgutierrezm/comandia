# ADR-012 — Terminal compartida: identidad de operación por PIN

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-09-08 |
| **Iteración** | Evolución (posterior a la hoja de ruta §14) |
| **Reemplaza a** | — |

> **No reemplaza ninguna ADR.** Introduce un modelo NUEVO —la terminal compartida operada por
> PIN— que **complementa** ADR-008 y **enmienda** la premisa de `ESPECIFICACIÓN_MAESTRA` §4.2/§6.3
> *"la terminal queda abierta [bajo una sesión]"* para el caso de la terminal fija compartida. La
> autorización por PIN de ADR-008 queda **intacta y separada**: son dos usos distintos del mismo PIN.

---

## Decisión

En una terminal marcada como **compartida**, cada operador abre una **sesión de operación corta**
tecleando su PIN sobre una **credencial de dispositivo** (no de usuario); comanda y atiende bajo su
**rol activo** (D9), y la terminal vuelve a un **estado neutro** al salir o por inactividad. La
autorización por PIN (ADR-008) sigue existiendo, sin cambios, para las acciones sensibles.

---

## Contexto

- **Autenticación actual (D54):** por USUARIO (token Sanctum o sesión web). La terminal no tiene
  credencial propia: es la cabecera `X-Terminal` sobre el token del usuario, validada contra el
  alcance de la membresía (`ResolveTenantContext`).
- **§4.2/§6.3:** *"la terminal queda abierta; cada acción sensible pide PIN e identifica al actor
  real"*. Hoy el PIN sólo **autoriza** (ADR-008), no da identidad de operación.
- **D8 / §4.1:** existen **empleados sin cuenta** —"puede existir empleado sin credenciales de
  acceso (ej. lavaloza en nómina que jamás inicia sesión)"—. Tienen membresía + PIN, no login.
- **Atribución (§6.3, ya implementada):** cada comanda guarda su actor (`created_by_membership_id`);
  la cuenta guarda su mesero (`waiter_membership_id`).
- **Realidad del sector:** muchos locales operan con **terminales fijas compartidas** (una o dos
  estaciones por las que rotan los meseros), no con un dispositivo por persona.

---

## Problema

Con el modelo actual, una terminal fija compartida obliga a una de dos, y ambas son malas:

1. **Login de usuario por mesero** (cerrar/abrir sesión con contraseña en cada relevo): fricción
   inviable en hora pico y, sobre todo, **excluye al personal sin cuenta (D8)** —que no tiene con
   qué autenticarse—, dejándolo sin poder comandar.
2. **Todos bajo un único login**: se pierde la **atribución** —quién comandó, quién cobró—, que es
   justo lo que §6.3 y la auditoría antifraude (§9) exigen conservar.

Si nadie decide, la omisión es una de esas dos: o se excluye a parte del personal, o se pierde la
atribución. Ninguna es aceptable para el arquetipo de restaurante con terminal compartida.

---

## Alternativas

### A. Login de usuario por mesero en la terminal compartida
- **Qué implica:** cada mesero inicia y cierra sesión (usuario + contraseña) en la estación compartida.
- **A favor:** ninguna infra nueva; atribución correcta.
- **En contra:** fricción brutal en el relevo; y **el personal sin cuenta (D8) simplemente no puede
  operar**. Es el modo de fallo que este ADR existe para evitar.

### B. Un solo login (gerente) + PIN-switch sólo para atribuir
- **Qué implica:** la estación lleva el token personal de un gerente; cada mesero teclea su PIN para
  que sus comandas se le atribuyan.
- **A favor:** poca infra; reutiliza el token de usuario existente.
- **En contra:** un **token personal de gerente vive en un dispositivo compartido** —si se roba o se
  deja abierto, actúa como el gerente en remoto, con todos sus permisos—. Acopla la estación a que un
  gerente concreto esté logueado. La superficie de riesgo es exactamente la peor.

### C. Credencial de DISPOSITIVO + sesión de operación por PIN
- **Qué implica:** la terminal se **enrola una vez** y obtiene un **token de dispositivo** (sin
  usuario, con permisos acotados a operar el POS de ese tenant/sucursal/terminal, revocable). Sobre
  esa base, cada mesero teclea su PIN → **sesión de operación corta** bajo su rol activo; "Salir" o
  la inactividad la cierran y vuelven al bloqueo.
- **A favor:** **ningún token personal** en el dispositivo compartido; el radio de daño del device
  token está acotado (sólo POS, revocable); **incluye al personal sin cuenta (D8)**, que nunca tuvo
  token; atribución limpia por operador; el PIN opera dentro de una terminal ya autenticada
  —confianza física del local—, que es lo que hace aceptable su baja entropía.
- **En contra:** **infra nueva** —enrolamiento del dispositivo, un tipo de token de dispositivo, la
  sesión de operación efímera y el estado de bloqueo en la UI del POS—.

---

## Decisión tomada

**Alternativa C**, con estos límites como parte inseparable de la decisión:

1. **Sólo en terminales marcadas como compartidas** (nueva bandera en `Terminal`, p. ej.
   `is_shared`). Las demás siguen con login de usuario (handhelds, back-office). **Los dos modelos
   coexisten**; el tenant elige por terminal.
2. **Token de dispositivo:** sin usuario asociado; abilities acotadas a operar el POS de **ese**
   tenant/sucursal/terminal; **revocable**; emitido por alguien con permiso en un **enrolamiento**
   único (inicia sesión una vez, enrola el dispositivo). Nunca puede tocar administración ni otra
   sucursal (ADR-002).
3. **Sesión de operación por PIN:** valida PIN + bloqueo por intentos **reutilizando** la mecánica de
   ADR-008/D54; establece una sesión **corta**, ligada al token de dispositivo, bajo el **rol activo**
   del operador (aquí **sí** aplica D9, a diferencia de la autorización). Comandar/atender/cobrar se
   atribuye a esa membresía con lo que ya existe (`created_by_membership_id`, `waiter_membership_id`).
4. **Fin de la sesión de operación:** "Salir" explícito **o** inactividad (timeout corto, nuevo ajuste
   por sucursal — D20; sugerido ≈90 s). Al cerrarse, la terminal vuelve al **teclado de PIN**.
5. **Las acciones sensibles por encima del permiso del operador siguen exigiendo autorización por PIN
   de un superior (ADR-008), intacta.** Los dos usos del PIN no se confunden: **operar** (identidad,
   rol activo, sesión corta propia) vs **autorizar** (acto único de un tercero, unión de roles, sin
   sesión, de un solo uso).
6. **Cobro:** un "Mesero con cobro" (D29) cobra contra la caja abierta de la terminal; el actor del
   cobro es él, independiente de quién abrió la caja (§6.3, ya soportado).

**Queda fuera** (para ADRs/iteraciones futuras): biometría o NFC como identificación; **más de un
operador simultáneo en la MISMA terminal** (una terminal compartida = un operador a la vez; la
concurrencia real se logra con varias terminales/dispositivos); aprovisionamiento masivo de flota.

---

## Justificación

Prioridades del proyecto (§1: correctitud > **seguridad** > mantenibilidad > … > velocidad):

- **Correctitud / atribución:** cada comanda y cada cobro quedan a nombre de quien los hizo —lo que
  §6.3 y §9 exigen— sin obligar a un login por persona. La alternativa B lo consigue pero pagando en
  seguridad; la A lo consigue excluyendo personal.
- **Seguridad:** el PIN de 4–6 dígitos es de baja entropía, y por eso **no** se usa como credencial
  remota. Aquí sólo identifica **localmente** a quién opera una terminal **ya autenticada por su token
  de dispositivo** —confianza física del local—. El token de dispositivo acota el daño (sólo POS,
  revocable), radicalmente mejor que un token personal de gerente en un aparato compartido (B). El
  bloqueo por intentos (D54) sigue vigente.
- **Inclusión operativa:** es el único modelo que deja operar al **personal sin cuenta (D8)**, que hoy
  no puede tocar el POS.

Se acepta el costo de infra nueva porque las dos alternativas sin infra fallan en una prioridad más
alta (seguridad en B, correctitud/inclusión en A).

---

## Consecuencias

**Se gana**
- Terminales fijas compartidas reales, con relevo por PIN en segundos.
- Atribución por operador sin fricción; el personal sin cuenta (D8) puede operar.
- Ningún token personal viviendo en un dispositivo compartido; credencial de dispositivo revocable.

**Se paga**
- Infra nueva: enrolamiento, tipo de token de dispositivo, sesión de operación efímera, estado de
  bloqueo en la UI del POS, y un ajuste de timeout por sucursal.
- Una terminal compartida atiende **un operador a la vez**: la concurrencia dentro de una misma
  terminal no existe (la concurrencia real es por varias terminales/dispositivos).

**Reglas que quedan vigentes** (verificables en revisión o por test)
1. El token de dispositivo **no** puede ejecutar nada fuera de operar el POS de su
   tenant/sucursal/terminal. *Test: un token de dispositivo contra una ruta de administración o de
   otra sucursal recibe 403.*
2. Toda comanda/cobro en terminal compartida lleva el `actor` de la **sesión de operación** vigente.
   *Test de feature.*
3. La sesión de operación **caduca** por inactividad y por "Salir"; tras caducar, operar exige teclear
   PIN de nuevo. *Test: acción tras el timeout exige re-identificación.*
4. **ADR-008 intacta:** autorizar una acción sensible sigue siendo un acto de un tercero, por unión de
   roles, sin sesión y de un solo uso. *El test estructural de ADR-008 sigue verde.*
5. Bloqueo del PIN por N intentos fallidos (D54), también en la identificación de operación.

**Puerta de salida**
Si el PIN de 4–6 dígitos resulta insuficiente para la confianza del local (p. ej. rotación alta con
suplantación), la corrección es **aditiva**: exigir un segundo factor por operación sensible o subir
la longitud/forma del identificador (NFC/biométrico), sin rediseñar el modelo de token de dispositivo.
La señal que lo justificaría: incidencias de suplantación en terminal compartida en la operación real.
