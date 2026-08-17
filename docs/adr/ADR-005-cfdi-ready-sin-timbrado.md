# ADR-005 — CFDI-ready sin timbrado en v1; el timbrado es la primera gran evolución

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 7 (Promociones + Clientes/CFDI-ready) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §13 y ESPECIFICACION_MAESTRA §6.6 (D41).

---

## Decisión

v1 captura y valida **todos los datos fiscales** necesarios para emitir un CFDI
—RFC, razón social a la letra del SAT, código postal fiscal, régimen fiscal y uso
CFDI, contra catálogos oficiales— y emite tickets con **folio facturable**, pero
**no timbra**. El timbrado ante un PAC es la primera gran evolución del producto.

---

## Contexto

- Mercado México exclusivamente en v1: MXN, es-MX, IVA mexicano, CFDI
  (ESPECIFICACION_MAESTRA §2).
- El cliente puede tener 0..N perfiles fiscales y 0..N direcciones
  (D42).
- Timbrar exige: contrato con un PAC, custodia de los CSD del tenant (llave privada
  y certificado), manejo de cancelaciones con acuse, complementos de pago y factura
  global de operaciones con público general.
- Los secretos —credenciales de pasarela y futuros CSD— van cifrados en reposo
  (ARQUITECTURA_MAESTRA §10.4).
- Prioridad del negocio: poner el MVP en manos de un negocio real lo antes posible
  (D1).

---

## Problema

El timbrado es un subsistema completo, no una funcionalidad: integración con un
tercero regulado, custodia de material criptográfico ajeno, un flujo de cancelación
con reglas del SAT que cambian, y responsabilidad legal sobre comprobantes fiscales
de otra empresa. Meterlo en v1 retrasa la salida a operación real de forma
significativa.

Pero el error opuesto es peor: si v1 no captura los datos fiscales con validación
real, al llegar el timbrado no habrá datos que timbrar. Habrá RFCs mal escritos,
razones sociales que no coinciden con el SAT y códigos postales inventados,
acumulados durante meses de operación. Y limpiar eso a posteriori es un trabajo
manual que el tenant hará de mala gana, cliente por cliente.

---

## Alternativas

### A. Timbrado completo en v1
- **A favor:** producto fiscalmente completo desde el primer día; diferenciador
  comercial fuerte en México.
- **En contra:** retrasa la salida a operación; introduce custodia de CSD y
  responsabilidad legal antes de tener un cliente; obliga a elegir PAC sin volumen
  con el que negociar.

### B. Sin nada fiscal en v1
- **A favor:** máxima velocidad.
- **En contra:** deuda de datos irreversible en la práctica. El día del timbrado, la
  base está contaminada y el trabajo de limpieza recae en el usuario.

### C. CFDI-ready: captura y validación completas, sin timbrado
- **A favor:** el modelo de datos y las validaciones quedan correctos desde el día
  uno; el tenant acumula datos fiscales limpios; el timbrado se vuelve un módulo
  que se conecta, no un rediseño.
- **En contra:** el usuario captura datos fiscales que todavía no producen una
  factura, lo que hay que explicar; queda una expectativa comercial pendiente.

---

## Decisión tomada

**Alternativa C.**

Dentro de v1:
- Captura **validada** de RFC (persona física y moral), razón social a la letra del
  SAT, código postal fiscal, régimen fiscal y uso CFDI, contra los **catálogos
  oficiales**.
- 0..N perfiles fiscales por cliente y 0..N direcciones.
- Ticket con **folio facturable**, para que el consumidor final pueda pedir su
  factura después por el canal que el tenant use hoy.
- Precios **IVA incluido** como dato maestro con desglose interno calculado, tasa
  configurable (16%/8%/exento) por tenant con override por sucursal (D30, §6.1).

Fuera de v1, con el modelo de datos preparado: timbrado con PAC, custodia de CSD,
cancelaciones, complementos de pago y factura global.

---

## Justificación

La decisión separa dos cosas que suelen confundirse: **la calidad del dato fiscal**
y **la emisión del comprobante**. La primera es barata ahora y carísima después,
porque su costo se paga en datos sucios acumulados. La segunda es caroísima ahora y
del mismo precio después, porque es integración con un tercero.

Hacer lo barato-ahora y postergar lo caro-igual-después es la asignación correcta
del tiempo del equipo, y respeta la prioridad de salir a operación real temprano sin
generar deuda de datos.

---

## Consecuencias

**Se gana**
- Salida a operación real más temprana.
- Datos fiscales limpios y validados desde el primer cliente.
- El timbrado queda como módulo conectable, no como rediseño del dominio de
  clientes.

**Se paga**
- El tenant que necesita facturar timbrado desde el día uno no es cliente todavía:
  es una restricción comercial explícita.
- Hay que explicar en la UI por qué se piden datos fiscales que aún no generan
  factura.

**Reglas que quedan vigentes**
1. La validación de RFC, régimen y uso CFDI es **real** desde v1, contra catálogos
   oficiales. Prohibido aceptar texto libre en esos campos.
2. Todo ticket lleva folio facturable.
3. Los CSD, cuando existan, se guardan cifrados en reposo y **jamás** aparecen en
   logs (ARQUITECTURA_MAESTRA §10.4).
4. El modelo de datos de perfiles fiscales se diseña pensando en el timbrado, no en
   lo que v1 usa.

**Puerta de salida**
No hay que revertir nada: el timbrado se **agrega**. La señal para construirlo: el
primer tenant cuyo volumen de facturación haga del trámite manual un problema
operativo real, o la necesidad comercial de competir con quien ya timbra.
