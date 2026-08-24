# Registro de decisiones de arquitectura (ADR)

Índice de las ADR vigentes de Comandia. La tabla resumen normativa vive en
`docs/ARQUITECTURA_MAESTRA.md` §13; estos archivos son la versión extendida de cada
decisión, con su contexto, alternativas descartadas, consecuencias y puerta de
salida.

| ADR | Decisión | Estado | Iteración |
|---|---|---|---|
| [ADR-001](ADR-001-monolito-modular.md) | Monolito modular con fronteras por eventos; extraíble, no distribuido | Aprobada | transversal |
| [ADR-002](ADR-002-multi-tenancy.md) | Multi-tenancy: base compartida + `tenant_id` + reglas A/B + puerta de salida | Aprobada | 1 |
| [ADR-003](ADR-003-editor-layout-svg-vue.md) | Editor de layout y piso de venta en SVG + Vue puro, coordenadas lógicas | Aprobada | 6 |
| [ADR-004](ADR-004-diario-financiero-inmutable.md) | Finanzas: diario inmutable tipado con origen; cortes calculados | Aprobada | 5 |
| [ADR-005](ADR-005-cfdi-ready-sin-timbrado.md) | CFDI-ready sin timbrado en v1; timbrado como primera gran evolución | Aprobada | 7 |
| [ADR-006](ADR-006-motor-reportes-declarativo.md) | Motor de reportes declarativo: definiciones + endpoint genérico + export por colas | Aprobada | 7 |
| [ADR-007](ADR-007-frontera-ecommerce-core.md) | Frontera E-commerce/Core: publicación como capa, una sola fuente de verdad | Aprobada | 8 |
| [ADR-008](ADR-008-autorizacion-por-pin-excepcion-rol-activo.md) | Autorización por PIN: excepción acotada a la regla del rol activo (D9) | Aprobada | 1 |
| [ADR-009](ADR-009-registro-de-datasets-de-reporte.md) | Registro de datasets de reporte: cada módulo dueño registra su definición; el motor sólo ejecuta | Aprobada | 7 |
| [ADR-010](ADR-010-venta-en-linea-tipo-propio-sin-sesion.md) | Venta en línea: tipo de movimiento propio (`OnlineSale`) sin sesión de caja; refina ADR-007 y §6.3 | Aprobada | 8 |

## Cómo se agrega una ADR

1. Copiar [`PLANTILLA.md`](PLANTILLA.md) a `ADR-0NN-titulo-en-kebab-case.md`.
2. Numeración consecutiva, sin reutilizar números. Una ADR obsoleta **no se borra**:
   se marca como reemplazada y se enlaza a la que la sustituye.
3. Agregar la fila a esta tabla y a la de `ARQUITECTURA_MAESTRA.md` §13.

## Cuándo hace falta una ADR

- Toda decisión que **contradiga una ADR vigente**. No hay cambios silenciosos: se
  redacta la ADR que la reemplaza explícitamente. Detectar contradicciones es
  responsabilidad del arquitecto en cada iteración.
- Introducir microservicios, event sourcing, CQRS formal, Elasticsearch, Kafka o
  Kubernetes: **prohibidos sin ADR aprobada** (ADR-001).
- Cualquier decisión estructural que los documentos maestros no cubran y que
  condicione iteraciones posteriores.

Las decisiones de producto y de implementación que **no** contradicen ninguna ADR se
anotan en [`../REGISTRO_DECISIONES.md`](../REGISTRO_DECISIONES.md), continuación del
registro D1–D57 de la Especificación Maestra.
