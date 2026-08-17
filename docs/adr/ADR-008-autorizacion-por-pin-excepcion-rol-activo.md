# ADR-008 — Autorización por PIN: excepción acotada a la regla del rol activo

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | 2026-08-17 |
| **Iteración** | 1 (Shared Kernel) |
| **Reemplaza a** | — |

> **No reemplaza ninguna ADR.** Registra una **excepción acotada** a la decisión D9
> (`ESPECIFICACIÓN_MAESTRA` §8), que también es regla no negociable de `CLAUDE.md`:
> *"la verificación de permisos evalúa el contexto {tenant, rol activo, sucursal activa},
> no la suma de roles"*. Existe porque una excepción a una regla no negociable tiene que
> estar escrita, aprobada y acotada — no descubierta después leyendo el código.

---

## Decisión

En el **único** endpoint de autorización por PIN, el permiso del autorizador se evalúa
contra la **unión de sus roles** en el tenant, no contra un rol activo. Toda autorización,
concedida o denegada, queda auditada identificando por separado a quien ejecuta y a quien
autoriza.

En cualquier otro punto del sistema, la verificación sigue siendo por **rol activo** (D9),
sin excepción.

---

## Contexto

- D9 establece roles múltiples con **rol activo**: el usuario opera bajo un rol a la vez y
  el permiso efectivo es el de ese rol, nunca la suma.
- `ESPECIFICACIÓN_MAESTRA` §4.2: *"la terminal queda abierta; cada acción sensible
  (descuento, cancelación post-comanda, abrir cajón, autorización) pide PIN e identifica
  al actor real, independiente de la sesión de caja"*.
- Las acciones sensibles del POS son la **zona de máxima auditoría** del producto (§6.3), y
  el robo hormiga —descuentos y cancelaciones usados para desviar efectivo— es el fraude
  característico del sector (§9).
- El PIN vive en la membresía, no en el usuario: el PIN de un tenant no es el PIN de otro.
- El flujo real: un mesero necesita aplicar un descuento, llama al gerente, el gerente
  teclea su PIN en la terminal del mesero y se va. **El gerente no abre sesión.**

---

## Problema

El rol activo es un atributo de una **sesión**. Quien autoriza con PIN no tiene sesión: se
acercó a una terminal ajena, tecleó cuatro dígitos y volvió a lo suyo. Por lo tanto **su
rol activo no está definido**, y D9 —escrita pensando en quien opera— no dice contra qué
evaluar su permiso.

Cualquier respuesta tiene un costo:

- Si se evalúa contra su rol por defecto, un gerente que **sí tiene** el permiso puede
  recibir "no autorizado" porque su rol por defecto es otro. El fallo ocurre delante del
  cliente, en hora pico, y la salida que el tenant encontrará es dar el rol de gerente a
  más gente de la necesaria — el resultado opuesto al que la regla buscaba.
- Si se le pide elegir rol, se añade una pantalla a una operación que dura segundos.
- Si se evalúa contra la unión de sus roles, se contradice D9.

---

## Alternativas

### A. Contra el `default_role_id` del autorizador
- **Qué implica:** el PIN autoriza sólo lo que permita el rol marcado como predeterminado de esa membresía.
- **A favor:** fiel a D9 sin excepción alguna; predecible; explicable en una frase.
- **En contra:** produce falsos negativos con permiso legítimo. El modo de fallo no es "no se pudo hacer la operación", es **"el tenant reconfigura los roles para que deje de fallar"**, y ese camino termina en roles más amplios de lo necesario. Traslada al tenant la carga de entender que sus roles autorizadores tienen que ser además roles por defecto.

### B. Contra la unión de los roles del autorizador, sólo en autorización por PIN, siempre auditado
- **Qué implica:** el PIN autoriza cualquier permiso que el autorizador tenga por cualquiera de sus roles en ese tenant. Nada más cambia.
- **A favor:** corresponde a la realidad del acto —puntual, de alguien que no está operando la terminal, sin sesión y por tanto sin rol activo—. El control compensatorio no hay que inventarlo: registrar al actor real es exactamente lo que §4.2 y §6.3 ya exigen. Cero fricción en el flujo de operación.
- **En contra:** es una excepción a una regla no negociable, y las excepciones se propagan si no se acotan. Un autorizador con un rol amplio y otro estrecho autoriza según el amplio, aunque en su operación diaria use el estrecho.

### C. Exigir que el autorizador elija rol al teclear el PIN
- **Qué implica:** dos pasos: elegir rol, teclear PIN.
- **A favor:** sin excepción a D9 y sin ambigüedad conceptual.
- **En contra:** fricción inaceptable en el momento más ocupado del turno. Una autorización que tarda el doble se rodea: el gerente termina dando su PIN al mesero, que es la peor pérdida de seguridad posible y además destruye el valor de la auditoría —el registro diría "el gerente autorizó" cuando no estaba presente—.

---

## Decisión tomada

**Alternativa B**, con estos límites como parte inseparable de la decisión:

1. La excepción vive en **un solo endpoint**: `POST /api/v1/authorizations`. Ninguna otra
   ruta, servicio o vista consulta la unión de roles.
2. El autorizador debe tener **membresía activa** en el tenant. La unión es de sus roles
   **en ese tenant**, nunca de roles en otros tenants (ADR-002).
3. La autorización emitida es de **un solo uso**, ligada a la acción concreta que la pidió
   y con vida corta (≈2 minutos). La terminal permanece abierta; la autorización no.
4. **Toda** autorización se audita, concedida y denegada, con
   `actor_membership_id` (quien ejecuta) y `authorized_by_membership_id` (quien autoriza)
   como columnas distintas.
5. Rate limiting por terminal y por IP, y bloqueo de la membresía tras N intentos fallidos
   (D54, D55).

---

## Justificación

La regla del rol activo existe para que nadie ejerza, sin darse cuenta, permisos que su
función del momento no requiere. Esa preocupación es sobre **operar**: alguien que pasa el
turno dentro del sistema acumulando capacidades de todos sus roles.

Una autorización por PIN no es operar. Es un acto único, explícito, dirigido a un permiso
concreto, hecho por alguien que está de pie junto a una terminal que no es la suya. Para
ese acto, el mecanismo de control correcto no es limitar el conjunto de permisos: es
**registrar quién lo hizo**. Y eso el sistema ya lo hace por diseño, con dos columnas de
actor en la bitácora y un reporte dedicado.

La alternativa A conserva la letra de D9 y pierde su espíritu, porque su modo de fallo
empuja al tenant a repartir roles más amplios. Prefiero una excepción escrita y acotada a
una regla intacta que se erosiona en la configuración del cliente.

---

## Consecuencias

**Se gana**
- El flujo de autorización funciona a la primera para quien tiene el permiso, sin obligar
  al tenant a entender la interacción entre rol por defecto y capacidad de autorizar.
- Se elimina el incentivo a compartir PIN, que es lo que destruiría la auditoría.
- La operación no gana pasos en el momento de más presión del turno.

**Se paga**
- Una excepción a una regla no negociable. El costo real es de vigilancia: hay que impedir
  que se extienda.
- Un autorizador con roles de amplitud distinta autoriza según el más amplio. Es visible en
  la configuración de roles del tenant, no oculto.

**Reglas que quedan vigentes** (verificables)
1. La unión de roles se consulta **exclusivamente** en el servicio de autorización por PIN.
   *Test estructural: falla si aparece en cualquier otro punto del código.*
2. Toda autorización por PIN produce un registro de auditoría, concedida o denegada, con
   los dos actores diferenciados. *Test de feature obligatorio.*
3. La autorización es de un solo uso, ligada a la acción y con expiración.
   *Test: reutilizarla falla; usarla para otra acción falla; usarla vencida falla.*
4. En todo lo demás, D9 sigue intacta: la verificación es por rol activo.
   *Test: usuario con dos roles operando bajo el rol sin el permiso recibe 403 aunque el
   otro rol lo tenga.*

**Puerta de salida**
Si la operación real muestra que los tenants otorgan capacidad de autorizar con demasiada
holgura, la corrección es **aditiva y no rediseña nada**: marcar los roles que pueden
autorizar con una bandera (`roles.can_authorize`) y exigir que la unión se restrinja a
esos. La señal que lo justificaría: reportes de descuentos donde el autorizador habitual
sea alguien cuyo puesto no debería autorizar.
