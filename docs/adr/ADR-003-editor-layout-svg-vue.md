# ADR-003 — Editor de layout y piso de venta en SVG + Vue puro, con coordenadas lógicas

| | |
|---|---|
| **Estado** | Aprobada |
| **Fecha** | Agosto 2026 |
| **Iteración** | 6 (Mesas/Layout + tiempo real) |
| **Reemplaza a** | — |

> Extraída de ARQUITECTURA_MAESTRA §9 y §13, y ESPECIFICACION_MAESTRA §6.4.

---

## Decisión

El editor de planos de mesas y la vista de piso de venta se construyen con **SVG y
Vue 3 sin librería de canvas**, persistiendo **coordenadas lógicas** —nunca
píxeles—, y el **mismo componente de render** sirve al editor y al piso de venta en
modo lectura.

---

## Contexto

- Cada sucursal puede tener múltiples planos y zonas (D34).
- Del editor se persiste por mesa: `x`, `y`, ancho, alto, rotación, forma y zona
  (ESPECIFICACION_MAESTRA §6.4).
- La vista de piso muestra estado vivo por mesa —libre, ocupada, cuenta
  solicitada, por limpiar (configurable), y "reservada" previsto en el enum—
  actualizado por Reverb.
- El POS es una superficie **táctil**, a pantalla completa, en tabletas y monitores
  de tamaños dispares (ARQUITECTURA_MAESTRA §9).
- La unión temporal de mesas es una operación del piso, no del editor
  (D32).

---

## Problema

Editor y piso de venta muestran lo mismo con distinta interacción. Si se
implementan como dos vistas separadas, divergen: se agrega una forma de mesa en el
editor y el piso la dibuja mal, o se cambia el criterio de una zona y sólo uno de
los dos lo respeta. El error se descubre en operación, con el restaurante lleno.

El segundo problema es la persistencia. Guardar píxeles amarra el plano a la
resolución del dispositivo en que se dibujó: el mismo plano visto en una tableta de
10" y en un monitor de 27" deja de ser el mismo plano, y cualquier cambio de
diseño de la UI invalida los datos guardados.

---

## Alternativas

### A. Canvas 2D con una librería de escena (Konva, Fabric)
- **Qué implica:** un árbol de escena imperativo dentro de un `<canvas>`.
- **A favor:** rendimiento superior con miles de objetos; herramientas de
  manipulación ya resueltas.
- **En contra:** el estado vive en la librería y hay que sincronizarlo a mano con
  Vue; sin DOM no hay accesibilidad ni objetivos táctiles nativos; una dependencia
  más en el camino crítico del POS. El rendimiento superior es irrelevante: un
  plano de restaurante son decenas de mesas, no miles.

### B. SVG + Vue puro, coordenadas lógicas
- **Qué implica:** cada mesa es un elemento SVG renderizado por Vue desde el
  estado reactivo; el editor añade manejadores de arrastre.
- **A favor:** el estado es el mismo objeto Vue que se persiste; el render es
  declarativo, así que editor y piso comparten componente por construcción; cada
  mesa es un nodo del DOM con área táctil real; cero dependencias nuevas; se
  depura con el inspector del navegador.
- **En contra:** hay que implementar arrastre, redimensión y rotación a mano;
  degradaría con cientos de nodos animados —irrelevante a esta escala—.

### C. Editor por formulario, sin plano visual
- **Qué implica:** capturar posiciones numéricamente.
- **A favor:** trivial de construir.
- **En contra:** inutilizable para el usuario; el plano de mesas es precisamente la
  interfaz que un mesero puede leer de un vistazo.

---

## Decisión tomada

**Alternativa B.** SVG con Vue 3 puro, sin librería de escena.

- Se persisten **coordenadas lógicas**: la unidad es del modelo, no de la pantalla.
  El viewBox del SVG hace la traducción a píxeles en cada dispositivo.
- El **mismo componente** de render sirve al editor (interactivo) y al piso de
  venta (lectura con estado vivo). El modo es una propiedad, no otra vista.
- El estado por mesa en el piso llega por Reverb, con respaldo de polling
  (ESPECIFICACION_MAESTRA §6.9).

---

## Justificación

La decisión la define la escala real: decenas de mesas por plano. El único
argumento fuerte a favor de canvas —rendimiento con miles de objetos— no aplica, y
sin él sólo quedan sus costos: una dependencia más, un estado duplicado y la
pérdida del DOM.

Compartir el componente entre editor y piso no es una optimización: es la forma de
hacer **estructuralmente imposible** que divergan, que es el riesgo caro de este
módulo.

---

## Consecuencias

**Se gana**
- Editor y piso que no pueden divergir.
- Planos independientes de la resolución: el mismo plano se ve correcto en tableta
  y en monitor.
- Objetivos táctiles nativos del DOM, requisito del POS.
- Cero dependencias nuevas en el camino crítico de la operación.

**Se paga**
- Arrastre, redimensión, rotación y adherencia a guías se implementan a mano.
- Techo de rendimiento más bajo, aceptado explícitamente por la escala del
  dominio.

**Reglas que quedan vigentes**
1. Prohibido persistir píxeles. La base guarda coordenadas lógicas.
2. Un solo componente de render para editor y piso de venta.
3. El enum de estado de mesa incluye `reservada` desde v1 aunque reservaciones
   quede fuera del alcance (D33): agregar un valor al enum después es una
   migración; preverlo no cuesta nada.
4. La unión de mesas es un enlace temporal a una cuenta común, con separación
   automática al pagar. No modifica el plano.

**Puerta de salida**
Si algún día se necesita un plano de cientos de elementos con animación continua,
el reemplazo es el motor de render, no el modelo de datos: las coordenadas lógicas
sobreviven al cambio. Requeriría ADR nueva.
